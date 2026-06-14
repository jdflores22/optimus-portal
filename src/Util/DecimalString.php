<?php

namespace App\Util;

/**
 * Normalizes Doctrine decimal columns (stored as string) with float-friendly accessors.
 */
final class DecimalString
{
    public static function fromFloat(?float $value, int $scale = 2): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format($value, $scale, '.', '');
    }

    public static function toFloat(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    public static function toFloatOrZero(?string $value): float
    {
        return (float) ($value ?? '0');
    }
}
