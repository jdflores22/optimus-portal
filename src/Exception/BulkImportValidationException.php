<?php

namespace App\Exception;

use RuntimeException;

/**
 * Task 4.4: Exception for bulk import validation failures with size-specific breakdown
 */
class BulkImportValidationException extends RuntimeException
{
    private array $rowErrors;
    private int $totalErrors;
    private array $affectedCyLocations;
    private array $sizeSpecificFailures;

    /**
     * @param array $rowErrors Array of errors with structure: ['row' => int, 'container_number' => string, 'error' => string]
     * @param array $affectedCyLocations Array of CY location names that have capacity issues
     * @param array $sizeSpecificFailures Array of size-specific capacity failures
     */
    public function __construct(
        array $rowErrors, 
        array $affectedCyLocations = [],
        array $sizeSpecificFailures = []
    ) {
        $this->rowErrors = $rowErrors;
        $this->totalErrors = count($rowErrors);
        $this->affectedCyLocations = $affectedCyLocations;
        $this->sizeSpecificFailures = $sizeSpecificFailures;

        $message = sprintf(
            'Bulk import failed. %d error%s found.',
            $this->totalErrors,
            $this->totalErrors === 1 ? '' : 's'
        );

        if (!empty($affectedCyLocations)) {
            $message .= sprintf(
                ' Affected CY locations: %s',
                implode(', ', $affectedCyLocations)
            );
        }

        parent::__construct($message, 400);
    }

    public function getRowErrors(): array
    {
        return $this->rowErrors;
    }

    public function getTotalErrors(): int
    {
        return $this->totalErrors;
    }

    public function getAffectedCyLocations(): array
    {
        return $this->affectedCyLocations;
    }

    public function getSizeSpecificFailures(): array
    {
        return $this->sizeSpecificFailures;
    }

    /**
     * Task 4.4: Check if this exception contains capacity failures
     */
    public function hasCapacityFailures(): bool
    {
        foreach ($this->rowErrors as $error) {
            if (isset($error['error_code']) && $error['error_code'] === 'BULK_IMPORT_CAPACITY_FAILURE') {
                return true;
            }
        }
        return false;
    }

    /**
     * Task 4.4: Get error code - returns BULK_IMPORT_CAPACITY_FAILURE if capacity failures exist
     */
    public function getErrorCode(): string
    {
        return $this->hasCapacityFailures() 
            ? 'BULK_IMPORT_CAPACITY_FAILURE' 
            : 'BULK_IMPORT_VALIDATION_FAILED';
    }

    /**
     * Task 4.4: Convert to array with size-specific breakdown
     */
    public function toArray(): array
    {
        $result = [
            'error' => $this->getErrorCode(),
            'message' => $this->getMessage(),
            'details' => [
                'total_errors' => $this->totalErrors,
                'affected_cy_locations' => $this->affectedCyLocations,
                'errors' => $this->rowErrors,
            ],
        ];

        // Add size-specific breakdown if capacity failures exist
        if ($this->hasCapacityFailures()) {
            $result['details']['size_specific_failures'] = $this->extractSizeSpecificBreakdown();
        }

        return $result;
    }

    /**
     * Task 4.3: Extract size-specific breakdown from errors
     */
    private function extractSizeSpecificBreakdown(): array
    {
        $breakdown = [];

        foreach ($this->rowErrors as $error) {
            if (isset($error['error_code']) && $error['error_code'] === 'BULK_IMPORT_CAPACITY_FAILURE') {
                $allocationId = $error['allocation_id'] ?? 'unknown';
                $terminalName = $error['allocation'] ?? 'Unknown Terminal';
                
                if (!isset($breakdown[$allocationId])) {
                    $breakdown[$allocationId] = [
                        'terminal_name' => $terminalName,
                        'allocation_id' => $allocationId,
                        'failures' => []
                    ];
                }

                if (isset($error['size_failures'])) {
                    foreach ($error['size_failures'] as $size => $failure) {
                        $breakdown[$allocationId]['failures'][$size] = [
                            'size' => $size,
                            'required' => $failure['required'],
                            'available' => $failure['available'],
                            'shortage' => $failure['shortage'],
                            'affected_containers' => $failure['containers']
                        ];
                    }
                }
            }
        }

        return array_values($breakdown);
    }

    /**
     * Task 4.3: Get a formatted error summary for display with size-specific details
     */
    public function getErrorSummary(): string
    {
        $summary = sprintf("Bulk import validation failed with %d error(s):\n\n", $this->totalErrors);
        
        foreach ($this->rowErrors as $error) {
            // Handle capacity failures with size-specific breakdown
            if (isset($error['error_code']) && $error['error_code'] === 'BULK_IMPORT_CAPACITY_FAILURE') {
                $summary .= sprintf(
                    "Capacity Failure - %s:\n",
                    $error['allocation'] ?? 'Unknown Terminal'
                );

                if (isset($error['size_failures'])) {
                    foreach ($error['size_failures'] as $size => $failure) {
                        $summary .= sprintf(
                            "  %s: Required %d, Available %d, Shortage %d\n",
                            $size,
                            $failure['required'],
                            $failure['available'],
                            $failure['shortage']
                        );
                        $summary .= sprintf(
                            "    Affected containers: %s\n",
                            implode(', ', $failure['containers'])
                        );
                    }
                }
                $summary .= "\n";
            } else {
                // Handle regular row errors
                $summary .= sprintf(
                    "Row %d - Container %s: %s\n",
                    $error['row'] ?? 'Unknown',
                    $error['container_number'] ?? 'Unknown',
                    $error['error'] ?? 'Unknown error'
                );
            }
        }

        if (!empty($this->affectedCyLocations)) {
            $summary .= sprintf(
                "\nAffected CY locations: %s",
                implode(', ', $this->affectedCyLocations)
            );
        }

        return $summary;
    }
}
