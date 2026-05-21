<?php

namespace App\Exception;

use RuntimeException;

class ConcurrentModificationException extends RuntimeException
{
    private ?int $allocationId;
    private ?string $conflictDetails;

    public function __construct(
        ?int $allocationId = null,
        ?string $conflictDetails = null
    ) {
        $this->allocationId = $allocationId;
        $this->conflictDetails = $conflictDetails ?? 'Another user has modified this allocation';

        $message = 'CY allocation has been modified by another user. Please refresh and try again.';
        
        if ($allocationId !== null) {
            $message = sprintf(
                'CY allocation (ID: %d) has been modified by another user. %s',
                $allocationId,
                $this->conflictDetails
            );
        }

        parent::__construct($message, 409);
    }

    public function getAllocationId(): ?int
    {
        return $this->allocationId;
    }

    public function getConflictDetails(): ?string
    {
        return $this->conflictDetails;
    }

    public function toArray(): array
    {
        return [
            'error' => 'concurrent_modification',
            'message' => $this->getMessage(),
            'details' => [
                'allocation_id' => $this->allocationId,
                'conflict_details' => $this->conflictDetails,
                'action_required' => 'Refresh and retry',
            ],
        ];
    }
}
