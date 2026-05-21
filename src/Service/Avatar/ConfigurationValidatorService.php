<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Configuration validation service for avatar colors system.
 * 
 * This service validates the avatar colors configuration structure,
 * provides meaningful error messages, and supports runtime reloading.
 */
class ConfigurationValidatorService implements ConfigurationValidatorServiceInterface
{
    private const REQUIRED_COLOR_FIELDS = ['bg', 'text', 'hex_bg', 'hex_text'];
    private const VALID_ROLE_INTENSITIES = [300, 400, 500, 600, 700];
    private const SUPPORTED_ROLES = [
        'SHIPPING_LINES_ADMIN',
        'SL_STAFF', 
        'EVALUATOR',
        'ACCOUNTING',
        'TERMINAL_TEAM'
    ];

    private array $validationErrors = [];

    /**
     * Validate complete avatar colors configuration.
     */
    public function validateConfiguration(array $config): bool
    {
        $this->validationErrors = [];

        // Validate top-level structure
        if (!$this->validateTopLevelStructure($config)) {
            return false;
        }

        $avatarConfig = $config['avatar_colors'] ?? $config;

        // Handle parameters wrapper
        if (isset($config['parameters']['avatar_colors'])) {
            $avatarConfig = $config['parameters']['avatar_colors'];
        } elseif (isset($config['avatar_colors'])) {
            $avatarConfig = $config['avatar_colors'];
        } else {
            $avatarConfig = $config;
        }

        // Validate each section
        $this->validateColorsSection($avatarConfig['colors'] ?? []);
        $this->validateRoleVariationsSection($avatarConfig['role_variations'] ?? []);
        $this->validateCacheSection($avatarConfig['cache'] ?? []);
        $this->validateAccessibilitySection($avatarConfig['accessibility'] ?? []);

        return empty($this->validationErrors);
    }

    /**
     * Validate configuration from YAML file.
     */
    public function validateConfigurationFile(string $filePath): bool
    {
        $this->validationErrors = [];

        if (!file_exists($filePath)) {
            $this->addError('configuration.file_not_found', "Configuration file not found: {$filePath}");
            return false;
        }

        try {
            $config = Yaml::parseFile($filePath);
        } catch (ParseException $e) {
            $this->addError('configuration.yaml_parse_error', "YAML parse error: {$e->getMessage()}");
            return false;
        }

        return $this->validateConfiguration($config);
    }

    /**
     * Get validation errors with meaningful messages.
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get formatted error messages for display.
     */
    public function getFormattedErrors(): string
    {
        if (empty($this->validationErrors)) {
            return '';
        }

        $messages = [];
        foreach ($this->validationErrors as $error) {
            $messages[] = "- {$error['message']}";
        }

        return "Avatar Colors Configuration Validation Errors:\n" . implode("\n", $messages);
    }

    /**
     * Validate and reload configuration at runtime.
     */
    public function validateAndReloadConfiguration(string $filePath): array
    {
        if (!$this->validateConfigurationFile($filePath)) {
            throw new \InvalidArgumentException($this->getFormattedErrors());
        }

        try {
            return Yaml::parseFile($filePath);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException("Failed to reload configuration: {$e->getMessage()}");
        }
    }

    /**
     * Validate top-level configuration structure.
     */
    private function validateTopLevelStructure(array $config): bool
    {
        // Check if configuration is wrapped in parameters
        if (isset($config['parameters']['avatar_colors'])) {
            return true;
        }

        // Check if configuration is direct avatar_colors
        if (isset($config['avatar_colors'])) {
            return true;
        }

        // Check if configuration is unwrapped (direct structure)
        if (isset($config['colors']) || isset($config['role_variations']) || 
            isset($config['cache']) || isset($config['accessibility'])) {
            return true;
        }

        $this->addError('configuration.missing_root', 
            'Configuration must contain avatar_colors section or be a valid avatar colors structure');
        return false;
    }

    /**
     * Validate colors section.
     */
    private function validateColorsSection(array $colors): void
    {
        if (empty($colors)) {
            $this->addError('colors.empty', 'Colors section cannot be empty');
            return;
        }

        if (!is_array($colors)) {
            $this->addError('colors.invalid_type', 'Colors section must be an array');
            return;
        }

        foreach ($colors as $index => $color) {
            $this->validateColorEntry($color, $index);
        }

        // Validate minimum number of colors
        if (count($colors) < 12) {
            $this->addError('colors.insufficient_count', 
                sprintf('At least 12 colors required, found %d', count($colors)));
        }
    }

    /**
     * Validate individual color entry.
     */
    private function validateColorEntry(mixed $color, int $index): void
    {
        if (!is_array($color)) {
            $this->addError("colors.{$index}.invalid_type", 
                "Color entry at index {$index} must be an array");
            return;
        }

        // Check required fields
        foreach (self::REQUIRED_COLOR_FIELDS as $field) {
            if (!isset($color[$field]) || empty($color[$field])) {
                $this->addError("colors.{$index}.missing_{$field}", 
                    "Color entry at index {$index} missing required field: {$field}");
            }
        }

        // Validate CSS class format
        if (isset($color['bg']) && !$this->isValidTailwindClass($color['bg'])) {
            $this->addError("colors.{$index}.invalid_bg_class", 
                "Invalid background CSS class at index {$index}: {$color['bg']}");
        }

        if (isset($color['text']) && !$this->isValidTextClass($color['text'])) {
            $this->addError("colors.{$index}.invalid_text_class", 
                "Invalid text CSS class at index {$index}: {$color['text']}");
        }

        // Validate hex color format
        if (isset($color['hex_bg']) && !$this->isValidHexColor($color['hex_bg'])) {
            $this->addError("colors.{$index}.invalid_hex_bg", 
                "Invalid hex background color at index {$index}: {$color['hex_bg']}");
        }

        if (isset($color['hex_text']) && !$this->isValidHexColor($color['hex_text'])) {
            $this->addError("colors.{$index}.invalid_hex_text", 
                "Invalid hex text color at index {$index}: {$color['hex_text']}");
        }

        // Validate color consistency
        if (isset($color['bg'], $color['hex_bg'])) {
            $this->validateColorConsistency($color['bg'], $color['hex_bg'], $index, 'background');
        }
    }

    /**
     * Validate role variations section.
     */
    private function validateRoleVariationsSection(array $roleVariations): void
    {
        if (!isset($roleVariations['enabled'])) {
            $this->addError('role_variations.missing_enabled', 
                'Role variations section must include enabled flag');
        }

        if (isset($roleVariations['enabled']) && !is_bool($roleVariations['enabled'])) {
            $this->addError('role_variations.invalid_enabled_type', 
                'Role variations enabled flag must be boolean');
        }

        if (isset($roleVariations['variations'])) {
            $this->validateRoleVariationsEntries($roleVariations['variations']);
        }
    }

    /**
     * Validate role variations entries.
     */
    private function validateRoleVariationsEntries(array $variations): void
    {
        if (!is_array($variations)) {
            $this->addError('role_variations.variations.invalid_type', 
                'Role variations must be an array');
            return;
        }

        foreach ($variations as $role => $config) {
            if (!in_array($role, self::SUPPORTED_ROLES, true)) {
                $this->addError("role_variations.variations.{$role}.unsupported_role", 
                    "Unsupported role: {$role}. Supported roles: " . implode(', ', self::SUPPORTED_ROLES));
            }

            if (!is_array($config)) {
                $this->addError("role_variations.variations.{$role}.invalid_type", 
                    "Role variation config for {$role} must be an array");
                continue;
            }

            if (!isset($config['intensity'])) {
                $this->addError("role_variations.variations.{$role}.missing_intensity", 
                    "Role variation for {$role} must include intensity");
                continue;
            }

            if (!in_array($config['intensity'], self::VALID_ROLE_INTENSITIES, true)) {
                $this->addError("role_variations.variations.{$role}.invalid_intensity", 
                    "Invalid intensity for {$role}: {$config['intensity']}. Valid values: " . 
                    implode(', ', self::VALID_ROLE_INTENSITIES));
            }
        }
    }

    /**
     * Validate cache section.
     */
    private function validateCacheSection(array $cache): void
    {
        if (isset($cache['enabled']) && !is_bool($cache['enabled'])) {
            $this->addError('cache.invalid_enabled_type', 
                'Cache enabled flag must be boolean');
        }

        if (isset($cache['ttl'])) {
            if (!is_int($cache['ttl']) || $cache['ttl'] < 0) {
                $this->addError('cache.invalid_ttl', 
                    'Cache TTL must be a non-negative integer');
            }
        }

        if (isset($cache['key_prefix']) && (!is_string($cache['key_prefix']) || empty($cache['key_prefix']))) {
            $this->addError('cache.invalid_key_prefix', 
                'Cache key prefix must be a non-empty string');
        }
    }

    /**
     * Validate accessibility section.
     */
    private function validateAccessibilitySection(array $accessibility): void
    {
        if (isset($accessibility['min_contrast_ratio'])) {
            $ratio = $accessibility['min_contrast_ratio'];
            if (!is_numeric($ratio) || $ratio < 1.0 || $ratio > 21.0) {
                $this->addError('accessibility.invalid_contrast_ratio', 
                    'Minimum contrast ratio must be between 1.0 and 21.0');
            }
        }

        if (isset($accessibility['enforce_wcag_aa']) && !is_bool($accessibility['enforce_wcag_aa'])) {
            $this->addError('accessibility.invalid_enforce_wcag_aa', 
                'Enforce WCAG AA flag must be boolean');
        }
    }

    /**
     * Validate Tailwind CSS class format.
     */
    private function isValidTailwindClass(string $class): bool
    {
        // Basic validation for Tailwind background classes
        return preg_match('/^bg-\w+-\d{3}$/', $class) === 1;
    }

    /**
     * Validate text CSS class.
     */
    private function isValidTextClass(string $class): bool
    {
        return in_array($class, ['text-white', 'text-black'], true);
    }

    /**
     * Validate hex color format.
     */
    private function isValidHexColor(string $color): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1;
    }

    /**
     * Validate consistency between CSS class and hex color.
     */
    private function validateColorConsistency(string $cssClass, string $hexColor, int $index, string $type): void
    {
        // This is a basic validation - in a real implementation, you might want
        // to maintain a mapping of Tailwind classes to hex values
        // For now, we just ensure the format is correct
        if (!$this->isValidTailwindClass($cssClass) || !$this->isValidHexColor($hexColor)) {
            return; // Already validated separately
        }

        // Additional consistency checks could be added here
        // For example, verifying that bg-blue-500 corresponds to the correct hex value
    }

    /**
     * Add validation error.
     */
    private function addError(string $code, string $message): void
    {
        $this->validationErrors[] = [
            'code' => $code,
            'message' => $message
        ];
    }
}