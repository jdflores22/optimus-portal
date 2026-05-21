<?php

namespace App\ValueObject;

/**
 * Value object representing CY allocation utilization metrics
 */
class UtilizationData
{
    public function __construct(
        private float $usedTEU,
        private float $availableTEU,
        private float $totalCapacityTEU,
        private float $utilizationPercentage,
        private int $containerCount
    ) {
    }

    public function getUsedTEU(): float
    {
        return $this->usedTEU;
    }

    public function getAvailableTEU(): float
    {
        return $this->availableTEU;
    }

    public function getTotalCapacityTEU(): float
    {
        return $this->totalCapacityTEU;
    }

    public function getUtilizationPercentage(): float
    {
        return $this->utilizationPercentage;
    }

    public function getContainerCount(): int
    {
        return $this->containerCount;
    }

    public function toArray(): array
    {
        return [
            'used_teu' => $this->usedTEU,
            'available_teu' => $this->availableTEU,
            'total_capacity_teu' => $this->totalCapacityTEU,
            'utilization_percentage' => $this->utilizationPercentage,
            'container_count' => $this->containerCount,
        ];
    }
}
