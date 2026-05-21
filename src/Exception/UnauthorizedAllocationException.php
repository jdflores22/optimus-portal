<?php

namespace App\Exception;

use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use RuntimeException;

class UnauthorizedAllocationException extends RuntimeException
{
    private ?int $allocationId;
    private ?string $allocationShippingLine;
    private ?string $userShippingLine;

    public function __construct(
        ?ShippingLineTerminalAllocation $allocation = null,
        ?ShippingLine $userShippingLine = null,
        ?int $allocationId = null
    ) {
        $this->allocationId = $allocationId ?? $allocation?->getId();
        $this->allocationShippingLine = $allocation?->getShippingLine()?->getName();
        $this->userShippingLine = $userShippingLine?->getName();

        $message = 'Cannot assign container to CY allocation from different shipping line';
        
        if ($this->allocationShippingLine && $this->userShippingLine) {
            $message .= sprintf(
                '. Allocation belongs to "%s" but user belongs to "%s"',
                $this->allocationShippingLine,
                $this->userShippingLine
            );
        }

        parent::__construct($message, 403);
    }

    public function getAllocationId(): ?int
    {
        return $this->allocationId;
    }

    public function getAllocationShippingLine(): ?string
    {
        return $this->allocationShippingLine;
    }

    public function getUserShippingLine(): ?string
    {
        return $this->userShippingLine;
    }

    public function toArray(): array
    {
        return [
            'error' => 'unauthorized_allocation',
            'message' => $this->getMessage(),
            'details' => [
                'allocation_id' => $this->allocationId,
                'allocation_shipping_line' => $this->allocationShippingLine,
                'user_shipping_line' => $this->userShippingLine,
                'reason' => 'Shipping line context mismatch',
            ],
        ];
    }
}
