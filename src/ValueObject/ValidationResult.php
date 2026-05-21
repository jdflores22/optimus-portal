<?php

namespace App\ValueObject;

/**
 * Value object representing capacity validation result
 */
class ValidationResult
{
    public function __construct(
        private bool $success,
        private ?string $message = null,
        private ?float $requiredTEU = null,
        private ?float $availableTEU = null,
        private ?float $shortage = null,
        private array $capacityDetails = []
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getRequiredTEU(): ?float
    {
        return $this->requiredTEU;
    }

    public function getAvailableTEU(): ?float
    {
        return $this->availableTEU;
    }

    public function getShortage(): ?float
    {
        return $this->shortage;
    }

    public function getCapacityDetails(): array
    {
        return $this->capacityDetails;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'required_teu' => $this->requiredTEU,
            'available_teu' => $this->availableTEU,
            'shortage' => $this->shortage,
            'capacity_details' => $this->capacityDetails,
        ];
    }

    public static function success(string $message = 'Validation successful'): self
    {
        return new self(true, $message);
    }

    public static function failure(
        string $message,
        float $requiredTEU,
        float $availableTEU,
        array $capacityDetails = []
    ): self {
        $shortage = $requiredTEU - $availableTEU;
        return new self(
            false,
            $message,
            $requiredTEU,
            $availableTEU,
            $shortage,
            $capacityDetails
        );
    }
}
