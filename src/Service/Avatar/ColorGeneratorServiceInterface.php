<?php

declare(strict_types=1);

namespace App\Service\Avatar;

/**
 * Interface for the core color generation algorithm.
 * 
 * This service implements the deterministic hash-based color generation
 * and role-based variations for avatar background colors.
 */
interface ColorGeneratorServiceInterface
{
    /**
     * Generate a base color from an identifier using deterministic algorithm.
     * 
     * @param string $identifier The identifier to generate color from
     * @return string The background CSS class (e.g., 'bg-blue-500')
     */
    public function generateBaseColor(string $identifier): string;

    /**
     * Apply role-based variation to a base color.
     * 
     * @param string $baseColor The base color CSS class
     * @param string $role The user role
     * @return string The modified color CSS class
     */
    public function applyRoleVariation(string $baseColor, string $role): string;

    /**
     * Get the list of available colors from configuration.
     * 
     * @return array Array of color configurations
     */
    public function getAvailableColors(): array;

    /**
     * Get color configuration by CSS class name.
     * 
     * @param string $cssClass The CSS class to find configuration for
     * @return array|null The color configuration or null if not found
     */
    public function getColorConfig(string $cssClass): ?array;
}