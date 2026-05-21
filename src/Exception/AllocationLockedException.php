<?php

namespace App\Exception;

use App\Entity\Container;
use App\Entity\Enum\AllocationStatus;
use RuntimeException;

class AllocationLockedException extends RuntimeException
{
    private ?string $containerNumber;
    private AllocationStatus $allocationStatus;

    public function __construct(
        ?string $containerNumber = null,
        AllocationStatus $allocationStatus = AllocationStatus::ALLOCATED,
        ?Container $container = null
    ) {
        $this->containerNumber = $containerNumber ?? $container?->getContainerNumber() ?? 'Unknown';
        $this->allocationStatus = $allocationStatus;

        $message = sprintf(
            'Cannot modify allocation for container %s. Allocation is locked because eDO has been generated. Current status: %s',
            $this->containerNumber,
            $this->allocationStatus->value
        );

        parent::__construct($message, 403);
    }

    public function getContainerNumber(): ?string
    {
        return $this->containerNumber;
    }

    public function getAllocationStatus(): AllocationStatus
    {
        return $this->allocationStatus;
    }

    public function toArray(): array
    {
        return [
            'error' => 'allocation_locked',
            'message' => $this->getMessage(),
            'details' => [
                'container_number' => $this->containerNumber,
                'allocation_status' => $this->allocationStatus->value,
                'reason' => 'eDO has been generated for this container',
            ],
        ];
    }
}
