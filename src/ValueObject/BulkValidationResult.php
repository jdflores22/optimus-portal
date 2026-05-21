<?php

namespace App\ValueObject;

/**
 * Value object representing the result of bulk container validation
 * Task 4.3: Enhanced with size-specific breakdown support
 */
class BulkValidationResult
{
    private bool $isValid;
    private array $errors;
    private array $warnings;
    private int $totalContainers;
    private int $validContainers;
    private array $capacityByAllocation;

    public function __construct(
        bool $isValid,
        array $errors = [],
        array $warnings = [],
        int $totalContainers = 0,
        int $validContainers = 0,
        array $capacityByAllocation = []
    ) {
        $this->isValid = $isValid;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->totalContainers = $totalContainers;
        $this->validContainers = $validContainers;
        $this->capacityByAllocation = $capacityByAllocation;
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getTotalContainers(): int
    {
        return $this->totalContainers;
    }

    public function getValidContainers(): int
    {
        return $this->validContainers;
    }

    public function getCapacityByAllocation(): array
    {
        return $this->capacityByAllocation;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    public function getWarningCount(): int
    {
        return count($this->warnings);
    }

    /**
     * Task 4.3: Check if validation result contains capacity failures
     */
    public function hasCapacityFailures(): bool
    {
        foreach ($this->errors as $error) {
            if (isset($error['error_code']) && $error['error_code'] === 'BULK_IMPORT_CAPACITY_FAILURE') {
                return true;
            }
        }
        return false;
    }

    /**
     * Task 4.3: Get size-specific capacity failures
     */
    public function getSizeSpecificFailures(): array
    {
        $failures = [];

        foreach ($this->errors as $error) {
            if (isset($error['error_code']) && $error['error_code'] === 'BULK_IMPORT_CAPACITY_FAILURE') {
                $failures[] = [
                    'terminal_name' => $error['allocation'] ?? 'Unknown',
                    'allocation_id' => $error['allocation_id'] ?? null,
                    'size_failures' => $error['size_failures'] ?? []
                ];
            }
        }

        return $failures;
    }

    /**
     * Task 4.3: Get summary of capacity failures by size
     */
    public function getCapacityFailureSummary(): array
    {
        $summary = [
            'total_20ft_shortage' => 0,
            'total_40ft_shortage' => 0,
            'affected_allocations' => []
        ];

        foreach ($this->errors as $error) {
            if (isset($error['error_code']) && $error['error_code'] === 'BULK_IMPORT_CAPACITY_FAILURE') {
                $terminalName = $error['allocation'] ?? 'Unknown';
                
                if (isset($error['size_failures'])) {
                    foreach ($error['size_failures'] as $size => $failure) {
                        if ($size === '20ft') {
                            $summary['total_20ft_shortage'] += $failure['shortage'];
                        } elseif ($size === '40ft') {
                            $summary['total_40ft_shortage'] += $failure['shortage'];
                        }
                    }
                }

                if (!in_array($terminalName, $summary['affected_allocations'])) {
                    $summary['affected_allocations'][] = $terminalName;
                }
            }
        }

        return $summary;
    }

    /**
     * Task 4.3: Convert to array with size-specific details
     */
    public function toArray(): array
    {
        $result = [
            'is_valid' => $this->isValid,
            'total_containers' => $this->totalContainers,
            'valid_containers' => $this->validContainers,
            'error_count' => $this->getErrorCount(),
            'warning_count' => $this->getWarningCount(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];

        if ($this->hasCapacityFailures()) {
            $result['capacity_failures'] = $this->getSizeSpecificFailures();
            $result['capacity_summary'] = $this->getCapacityFailureSummary();
        }

        return $result;
    }
}
