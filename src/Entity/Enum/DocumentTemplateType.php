<?php

namespace App\Entity\Enum;

enum DocumentTemplateType: string
{
    case NOA = 'NOA';
    case EDO = 'EDO';
    case MANIFEST_BL = 'MANIFEST_BL';
    case BILLING = 'BILLING';
    case OFFICIAL_RECEIPT = 'OFFICIAL_RECEIPT';
    case CERTIFICATE = 'CERTIFICATE';

    public function getLabel(): string
    {
        return match ($this) {
            self::NOA => 'Notice of Arrival (NOA)',
            self::EDO => 'Electronic Delivery Order (eDO)',
            self::MANIFEST_BL => 'Manifest / Bill of Lading',
            self::BILLING => 'Billing Statement',
            self::OFFICIAL_RECEIPT => 'Official Receipt',
            self::CERTIFICATE => 'Certificate',
        };
    }
}
