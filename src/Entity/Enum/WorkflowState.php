<?php

namespace App\Entity\Enum;

enum WorkflowState: string
{
    case MANIFEST_UPLOADED = 'manifest_uploaded';
    case NOA_GENERATED = 'noa_generated';
    case BL_GENERATED = 'bl_generated';
    case BL_UPLOADED = 'bl_uploaded';
    case BILLING_GENERATED = 'billing_generated';
    case PAYMENT_SUBMITTED = 'payment_submitted';
    case PAYMENT_VERIFIED = 'payment_verified';
    case EDO_GENERATED = 'edo_generated';
    case EDO_RELEASED = 'edo_released';

    public static function isValidTransition(self $from, self $to): bool
    {
        $validTransitions = [
            self::MANIFEST_UPLOADED->value => [self::NOA_GENERATED->value],
            self::NOA_GENERATED->value => [self::BL_GENERATED->value],
            self::BL_GENERATED->value => [self::BL_UPLOADED->value],
            self::BL_UPLOADED->value => [self::BILLING_GENERATED->value],
            self::BILLING_GENERATED->value => [self::PAYMENT_SUBMITTED->value],
            self::PAYMENT_SUBMITTED->value => [self::PAYMENT_VERIFIED->value, self::BILLING_GENERATED->value],
            self::PAYMENT_VERIFIED->value => [self::EDO_GENERATED->value],
            self::EDO_GENERATED->value => [self::EDO_RELEASED->value],
        ];

        return in_array($to->value, $validTransitions[$from->value] ?? []);
    }

    public function getDisplayName(): string
    {
        return match($this) {
            self::MANIFEST_UPLOADED => 'Manifest Uploaded',
            self::NOA_GENERATED => 'NOA Generated',
            self::BL_GENERATED => 'BL Generated',
            self::BL_UPLOADED => 'BL Uploaded',
            self::BILLING_GENERATED => 'Billing Generated',
            self::PAYMENT_SUBMITTED => 'Payment Submitted',
            self::PAYMENT_VERIFIED => 'Payment Verified',
            self::EDO_GENERATED => 'eDO Generated',
            self::EDO_RELEASED => 'eDO Released',
        };
    }
}
