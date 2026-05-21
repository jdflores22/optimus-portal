<?php

namespace App\ValueObject;

use DateTime;
use JsonSerializable;

/**
 * Container value object for API readiness
 * 
 * This value object represents a shipping container with all its properties
 * and provides validation and serialization methods for JSON API compatibility.
 */
class Container implements JsonSerializable
{
    private string $containerNumber;
    private string $sizeType;
    private DateTime $gateInDate;
    private int $dwellTime;
    private string $condition;
    private string $status;
    private string $location;
    private int $totalPausedDays;
    private ?DateTime $dwellTimePausedAt;
    private ?DateTime $nextNotificationDate;
    private ?DateTime $automaticReturnDate;

    public function __construct(
        string $containerNumber,
        string $sizeType,
        DateTime $gateInDate,
        int $dwellTime,
        string $condition,
        string $status,
        string $location,
        int $totalPausedDays = 0,
        ?DateTime $dwellTimePausedAt = null,
        ?DateTime $nextNotificationDate = null,
        ?DateTime $automaticReturnDate = null
    ) {
        $this->validateContainerNumber($containerNumber);
        $this->validateSizeType($sizeType);
        $this->validateCondition($condition);
        $this->validateStatus($status);
        $this->validateDwellTime($dwellTime);
        
        $this->containerNumber = $containerNumber;
        $this->sizeType = $sizeType;
        $this->gateInDate = $gateInDate;
        $this->dwellTime = $dwellTime;
        $this->condition = $condition;
        $this->status = $status;
        $this->location = $location;
        $this->totalPausedDays = $totalPausedDays;
        $this->dwellTimePausedAt = $dwellTimePausedAt;
        $this->nextNotificationDate = $nextNotificationDate;
        $this->automaticReturnDate = $automaticReturnDate;
    }

    public function getContainerNumber(): string
    {
        return $this->containerNumber;
    }

    public function getSizeType(): string
    {
        return $this->sizeType;
    }

    public function getGateInDate(): DateTime
    {
        return $this->gateInDate;
    }

    public function getDwellTime(): int
    {
        return $this->dwellTime;
    }

    public function getCondition(): string
    {
        return $this->condition;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getTotalPausedDays(): int
    {
        return $this->totalPausedDays;
    }

    public function getDwellTimePausedAt(): ?DateTime
    {
        return $this->dwellTimePausedAt;
    }

    public function getNextNotificationDate(): ?DateTime
    {
        return $this->nextNotificationDate;
    }

    public function getAutomaticReturnDate(): ?DateTime
    {
        return $this->automaticReturnDate;
    }

    /**
     * Check if dwell time is currently paused
     */
    public function isDwellTimePaused(): bool
    {
        return $this->dwellTimePausedAt !== null;
    }

    /**
     * Calculate TEU (Twenty-foot Equivalent Unit) for this container
     */
    public function getTeuCount(): int
    {
        return str_contains($this->sizeType, '20ft') ? 1 : 2;
    }

    /**
     * Get formatted gate in date for display
     */
    public function getFormattedGateInDate(): string
    {
        return $this->gateInDate->format('Y-m-d');
    }

    /**
     * Check if container is available for operations
     */
    public function isAvailable(): bool
    {
        return $this->status === 'Available';
    }

    /**
     * Serialize container data for JSON API responses
     */
    public function jsonSerialize(): array
    {
        return [
            'containerNumber' => $this->containerNumber,
            'sizeType' => $this->sizeType,
            'gateInDate' => $this->gateInDate->format('Y-m-d'),
            'dwellTime' => $this->dwellTime,
            'condition' => $this->condition,
            'status' => $this->status,
            'location' => $this->location,
            'teuCount' => $this->getTeuCount(),
            'isAvailable' => $this->isAvailable(),
            'totalPausedDays' => $this->totalPausedDays,
            'dwellTimePausedAt' => $this->dwellTimePausedAt?->format('Y-m-d H:i:s'),
            'nextNotificationDate' => $this->nextNotificationDate?->format('Y-m-d'),
            'automaticReturnDate' => $this->automaticReturnDate?->format('Y-m-d'),
            'isDwellTimePaused' => $this->isDwellTimePaused()
        ];
    }

    /**
     * Create Container from array data (for API integration)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['containerNumber'],
            $data['sizeType'],
            $data['gateInDate'] instanceof DateTime ? $data['gateInDate'] : new DateTime($data['gateInDate']),
            $data['dwellTime'],
            $data['condition'],
            $data['status'],
            $data['location'],
            $data['totalPausedDays'] ?? 0,
            isset($data['dwellTimePausedAt']) && $data['dwellTimePausedAt'] instanceof DateTime 
                ? $data['dwellTimePausedAt'] 
                : (isset($data['dwellTimePausedAt']) && $data['dwellTimePausedAt'] !== null 
                    ? new DateTime($data['dwellTimePausedAt']) 
                    : null),
            isset($data['nextNotificationDate']) && $data['nextNotificationDate'] instanceof DateTime 
                ? $data['nextNotificationDate'] 
                : (isset($data['nextNotificationDate']) && $data['nextNotificationDate'] !== null 
                    ? new DateTime($data['nextNotificationDate']) 
                    : null),
            isset($data['automaticReturnDate']) && $data['automaticReturnDate'] instanceof DateTime 
                ? $data['automaticReturnDate'] 
                : (isset($data['automaticReturnDate']) && $data['automaticReturnDate'] !== null 
                    ? new DateTime($data['automaticReturnDate']) 
                    : null)
        );
    }

    /**
     * Convert to array format (for backward compatibility)
     */
    public function toArray(): array
    {
        return [
            'containerNumber' => $this->containerNumber,
            'sizeType' => $this->sizeType,
            'gateInDate' => $this->gateInDate,
            'dwellTime' => $this->dwellTime,
            'condition' => $this->condition,
            'status' => $this->status,
            'location' => $this->location,
            'totalPausedDays' => $this->totalPausedDays,
            'dwellTimePausedAt' => $this->dwellTimePausedAt,
            'nextNotificationDate' => $this->nextNotificationDate,
            'automaticReturnDate' => $this->automaticReturnDate
        ];
    }

    private function validateContainerNumber(string $containerNumber): void
    {
        if (empty($containerNumber)) {
            throw new \InvalidArgumentException('Container number cannot be empty');
        }
    }

    private function validateSizeType(string $sizeType): void
    {
        if (empty($sizeType)) {
            throw new \InvalidArgumentException('Size type cannot be empty');
        }
    }

    private function validateCondition(string $condition): void
    {
        $validConditions = ['Good', 'Fair', 'Damaged'];
        
        if (!in_array($condition, $validConditions, true)) {
            throw new \InvalidArgumentException('Invalid condition. Valid conditions: ' . implode(', ', $validConditions));
        }
    }

    private function validateStatus(string $status): void
    {
        $validStatuses = ['Available', 'Reserved', 'Hold', 'In Transit', 'Maintenance', 'Pre-Forecast'];
        
        if (!in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException('Invalid status. Valid statuses: ' . implode(', ', $validStatuses));
        }
    }

    private function validateDwellTime(int $dwellTime): void
    {
        if ($dwellTime < 0) {
            throw new \InvalidArgumentException('Dwell time cannot be negative');
        }
    }
}