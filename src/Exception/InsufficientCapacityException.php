<?php

namespace App\Exception;

use App\Entity\ShippingLineTerminalAllocation;
use RuntimeException;

class InsufficientCapacityException extends RuntimeException
{
    private float $requiredTeu;
    private float $availableTeu;
    private float $shortageTeu;
    private ?ShippingLineTerminalAllocation $allocation;
    private ?string $terminalName;
    private ?string $containerSize;
    private int $requiredCount;
    private int $availableCount;

    public function __construct(
        float $requiredTeu,
        float $availableTeu,
        ?ShippingLineTerminalAllocation $allocation = null,
        ?string $terminalName = null,
        ?string $containerSize = null
    ) {
        $this->requiredTeu = $requiredTeu;
        $this->availableTeu = $availableTeu;
        $this->shortageTeu = $requiredTeu - $availableTeu;
        $this->allocation = $allocation;
        $this->terminalName = $terminalName ?? ($allocation?->getTerminal()?->getName() ?? 'Unknown Terminal');
        $this->containerSize = $containerSize;
        
        // Calculate container counts based on size
        if ($containerSize === '20ft') {
            $this->requiredCount = (int) $requiredTeu; // 1 TEU = 1 container for 20ft
            $this->availableCount = (int) $availableTeu;
        } elseif ($containerSize === '40ft') {
            $this->requiredCount = (int) ($requiredTeu / 2); // 2 TEU = 1 container for 40ft
            $this->availableCount = (int) ($availableTeu / 2);
        } else {
            // Fallback for TEU-based (no specific size)
            $this->requiredCount = (int) $requiredTeu;
            $this->availableCount = (int) $availableTeu;
        }

        // Generate size-specific message if container size is provided
        if ($containerSize !== null) {
            $message = sprintf(
                'Insufficient %s capacity at %s. Required: %d containers, Available: %d containers',
                $containerSize,
                $this->terminalName,
                $this->requiredCount,
                $this->availableCount
            );
        } else {
            // Fallback to TEU-based message
            $message = sprintf(
                'Insufficient capacity at %s. Required: %.1f TEU, Available: %.1f TEU, Shortage: %.1f TEU',
                $this->terminalName,
                $this->requiredTeu,
                $this->availableTeu,
                $this->shortageTeu
            );
        }

        parent::__construct($message, 400);
    }

    public function getRequiredTeu(): float
    {
        return $this->requiredTeu;
    }

    public function getAvailableTeu(): float
    {
        return $this->availableTeu;
    }

    public function getShortageTeu(): float
    {
        return $this->shortageTeu;
    }

    public function getAllocation(): ?ShippingLineTerminalAllocation
    {
        return $this->allocation;
    }

    public function getTerminalName(): ?string
    {
        return $this->terminalName;
    }

    public function getContainerSize(): ?string
    {
        return $this->containerSize;
    }

    public function getRequiredCount(): int
    {
        return $this->requiredCount;
    }

    public function getAvailableCount(): int
    {
        return $this->availableCount;
    }

    /**
     * Get error code based on container size
     * Task 3.2: Returns size-specific error codes
     */
    public function getErrorCode(): string
    {
        if ($this->containerSize === '20ft') {
            return 'INSUFFICIENT_20FT_CAPACITY';
        } elseif ($this->containerSize === '40ft') {
            return 'INSUFFICIENT_40FT_CAPACITY';
        }
        
        return 'INSUFFICIENT_CAPACITY';
    }

    public function toArray(): array
    {
        $details = [
            'terminal_id' => $this->allocation?->getTerminal()?->getId(),
            'terminal_name' => $this->terminalName,
            'allocation_id' => $this->allocation?->getId(),
        ];

        // Add size-specific fields if container size is provided
        if ($this->containerSize !== null) {
            $details['container_size'] = $this->containerSize;
            $details['required_count'] = $this->requiredCount;
            $details['available_count'] = $this->availableCount;
        } else {
            // Fallback to TEU-based fields
            $details['required_teu'] = $this->requiredTeu;
            $details['available_teu'] = $this->availableTeu;
            $details['shortage_teu'] = $this->shortageTeu;
        }

        return [
            'error' => $this->getErrorCode(),
            'message' => $this->getMessage(),
            'details' => $details,
        ];
    }
}
