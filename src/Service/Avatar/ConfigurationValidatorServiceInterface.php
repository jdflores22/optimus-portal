<?php

declare(strict_types=1);

namespace App\Service\Avatar;

/**
 * Interface for avatar colors configuration validation service.
 */
interface ConfigurationValidatorServiceInterface
{
    /**
     * Validate complete avatar colors configuration.
     */
    public function validateConfiguration(array $config): bool;

    /**
     * Validate configuration from YAML file.
     */
    public function validateConfigurationFile(string $filePath): bool;

    /**
     * Get validation errors with meaningful messages.
     */
    public function getValidationErrors(): array;

    /**
     * Get formatted error messages for display.
     */
    public function getFormattedErrors(): string;

    /**
     * Validate and reload configuration at runtime.
     */
    public function validateAndReloadConfiguration(string $filePath): array;
}