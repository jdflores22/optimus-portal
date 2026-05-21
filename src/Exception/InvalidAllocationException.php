<?php

namespace App\Exception;

use RuntimeException;

class InvalidAllocationException extends RuntimeException
{
    private ?int $allocationId;
    private string $validationReason;

    public function __construct(
        ?int $allocationId = null,
        string $validationReason = 'Allocation not found or inactive'
    ) {
        $this->allocationId = $allocationId;
        $this->validationReason = $validationReason;

        $message = $allocationId !== null
            ? sprintf('Invalid allocation (ID: %d). %s', $allocationId, $validationReason)
            : sprintf('Invalid allocation. %s', $validationReason);

        parent::__construct($message, 404);
    }

    public function getAllocationId(): ?int
    {
        return $this->allocationId;
    }

    public function getValidationReason(): string
    {
        return $this->validationReason;
    }

    public function toArray(): array
    {
        return [
            'error' => 'invalid_allocation',
            'message' => $this->getMessage(),
            'details' => [
                'allocation_id' => $this->allocationId,
                'reason' => $this->validationReason,
            ],
        ];
    }
}
