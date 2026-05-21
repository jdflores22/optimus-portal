<?php

namespace App\Utility;

/**
 * Utility class for billing calculations
 * 
 * Requirements: 7.2
 */
class BillingCalculator
{
    /**
     * Calculate total billing amount
     * 
     * @param int $expiredDays Number of expired days
     * @param float $perDayRate Rate per day
     * @return float Total amount
     */
    public function calculateAmount(int $expiredDays, float $perDayRate): float
    {
        if ($expiredDays < 0) {
            throw new \InvalidArgumentException('Expired days cannot be negative');
        }

        if ($perDayRate < 0) {
            throw new \InvalidArgumentException('Per day rate cannot be negative');
        }

        return $expiredDays * $perDayRate;
    }

    /**
     * Get per-day rate from system configuration
     * 
     * @param array $config System configuration array
     * @return float Per-day rate
     */
    public function getPerDayRateFromConfig(array $config): float
    {
        if (!isset($config['edo_expired_per_day_rate'])) {
            throw new \RuntimeException('eDO per-day rate not configured in system settings');
        }

        $rate = (float) $config['edo_expired_per_day_rate'];

        if ($rate <= 0) {
            throw new \RuntimeException('eDO per-day rate must be positive');
        }

        return $rate;
    }
}
