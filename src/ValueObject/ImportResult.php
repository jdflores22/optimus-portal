<?php

namespace App\ValueObject;

/**
 * Value object representing the result of a bulk container import operation
 */
class ImportResult
{
    private bool $success;
    private int $importedCount;
    private int $failedCount;
    private array $errors;
    private array $warnings;
    private array $importedContainerIds;
    private ?string $message;

    public function __construct(
        bool $success,
        int $importedCount = 0,
        int $failedCount = 0,
        array $errors = [],
        array $warnings = [],
        array $importedContainerIds = [],
        ?string $message = null
    ) {
        $this->success = $success;
        $this->importedCount = $importedCount;
        $this->failedCount = $failedCount;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->importedContainerIds = $importedContainerIds;
        $this->message = $message;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getImportedContainerIds(): array
    {
        return $this->importedContainerIds;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }
}
