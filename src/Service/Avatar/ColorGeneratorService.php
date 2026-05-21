<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use Psr\Log\LoggerInterface;

/**
 * Core color generation service implementing deterministic hash-based algorithm.
 * 
 * This service generates consistent background colors for user avatars using
 * FNV-1a hash algorithm and supports role-based color variations.
 */
class ColorGeneratorService implements ColorGeneratorServiceInterface
{
    private array $colors;
    private array $roleVariations;
    private bool $roleVariationsEnabled;

    public function __construct(array $avatarColorsConfig, private readonly LoggerInterface $logger)
    {
        try {
            $this->colors = $avatarColorsConfig['colors'] ?? [];
            $this->roleVariations = $avatarColorsConfig['role_variations']['variations'] ?? [];
            $this->roleVariationsEnabled = $avatarColorsConfig['role_variations']['enabled'] ?? false;
            
            if (empty($this->colors)) {
                $this->logger->warning('No colors configured in avatar colors config, using default fallback');
            }
            
            $this->logger->debug('ColorGeneratorService initialized', [
                'colors_count' => count($this->colors),
                'role_variations_enabled' => $this->roleVariationsEnabled,
                'role_variations_count' => count($this->roleVariations)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize ColorGeneratorService', [
                'error' => $e->getMessage(),
                'config' => $avatarColorsConfig
            ]);
            
            // Initialize with empty arrays to prevent further errors
            $this->colors = [];
            $this->roleVariations = [];
            $this->roleVariationsEnabled = false;
        }
    }

    /**
     * Generate a base color from an identifier using FNV-1a hash algorithm.
     */
    public function generateBaseColor(string $identifier): string
    {
        try {
            $baseColors = $this->getBaseColors();
            
            if (empty($baseColors)) {
                $this->logger->warning('No base colors available, using fallback color');
                return 'bg-meta-blue'; // Fallback if no colors configured
            }

            // Normalize identifier
            $normalizedIdentifier = $this->normalizeIdentifier($identifier);
            
            if (empty($normalizedIdentifier)) {
                $this->logger->warning('Identifier normalized to empty string', [
                    'original_identifier' => $identifier
                ]);
                // Use a default identifier to ensure consistent behavior
                $normalizedIdentifier = 'default';
            }
            
            // Use FNV-1a hash for consistent distribution
            $hash = $this->fnv1aHash($normalizedIdentifier);
            
            // Select color based on hash
            $colorIndex = $hash % count($baseColors);
            $selectedColor = $baseColors[$colorIndex]['bg'];
            
            $this->logger->debug('Base color generated', [
                'identifier_hash' => hash('sha256', $identifier),
                'normalized_identifier_hash' => hash('sha256', $normalizedIdentifier),
                'hash_value' => $hash,
                'color_index' => $colorIndex,
                'selected_color' => $selectedColor,
                'total_colors' => count($baseColors)
            ]);
            
            return $selectedColor;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate base color', [
                'identifier_hash' => hash('sha256', $identifier),
                'error' => $e->getMessage()
            ]);
            return 'bg-meta-blue'; // Fallback color
        }
    }

    /**
     * Apply role-based variation to a base color.
     */
    public function applyRoleVariation(string $baseColor, string $role): string
    {
        try {
            if (!$this->roleVariationsEnabled) {
                $this->logger->debug('Role variations disabled, returning base color', [
                    'base_color' => $baseColor,
                    'role' => $role
                ]);
                return $baseColor;
            }
            
            if (!isset($this->roleVariations[$role])) {
                $this->logger->debug('No variation configured for role, returning base color', [
                    'base_color' => $baseColor,
                    'role' => $role,
                    'available_roles' => array_keys($this->roleVariations)
                ]);
                return $baseColor;
            }

            $variation = $this->roleVariations[$role];
            $intensity = $variation['intensity'] ?? 500;

            // Extract color name from CSS class (e.g., 'bg-blue-500' -> 'blue')
            if (preg_match('/bg-(\w+)-\d+/', $baseColor, $matches)) {
                $colorName = $matches[1];
                $variedColor = "bg-{$colorName}-{$intensity}";
                
                // Check if the varied color exists in configuration
                if ($this->getColorConfig($variedColor) !== null) {
                    $this->logger->debug('Applied role variation successfully', [
                        'base_color' => $baseColor,
                        'varied_color' => $variedColor,
                        'role' => $role,
                        'intensity' => $intensity
                    ]);
                    return $variedColor;
                }
                
                $this->logger->warning('Varied color not found in configuration, using base color', [
                    'base_color' => $baseColor,
                    'attempted_varied_color' => $variedColor,
                    'role' => $role
                ]);
                return $baseColor;
            }

            $this->logger->warning('Could not extract color name from base color, using base color', [
                'base_color' => $baseColor,
                'role' => $role
            ]);
            return $baseColor;
        } catch (\Exception $e) {
            $this->logger->error('Failed to apply role variation', [
                'base_color' => $baseColor,
                'role' => $role,
                'error' => $e->getMessage()
            ]);
            return $baseColor; // Fallback to base color
        }
    }

    /**
     * Get the list of available colors from configuration.
     */
    public function getAvailableColors(): array
    {
        return $this->colors;
    }

    /**
     * Get only the base colors (intensity 500) for initial generation.
     */
    public function getBaseColors(): array
    {
        $baseColors = array_filter($this->colors, function($color) {
            return preg_match('/-500$/', $color['bg']);
        });
        
        // Re-index the array to ensure numeric keys start from 0
        return array_values($baseColors);
    }

    /**
     * Get color configuration by CSS class name.
     */
    public function getColorConfig(string $cssClass): ?array
    {
        try {
            foreach ($this->colors as $color) {
                if (isset($color['bg']) && $color['bg'] === $cssClass) {
                    return $color;
                }
            }
            
            $this->logger->debug('Color configuration not found', [
                'css_class' => $cssClass,
                'available_colors' => array_column($this->colors, 'bg')
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get color configuration', [
                'css_class' => $cssClass,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Normalize identifier for consistent hashing.
     */
    private function normalizeIdentifier(string $identifier): string
    {
        try {
            $normalized = strtolower(trim($identifier));
            
            // Remove special characters that might cause issues
            $normalized = preg_replace('/[^a-z0-9\s@._-]/', '', $normalized);
            
            return $normalized;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to normalize identifier, using original', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            return strtolower(trim($identifier));
        }
    }

    /**
     * FNV-1a hash implementation for consistent color distribution.
     */
    private function fnv1aHash(string $data): int
    {
        try {
            $hash = 2166136261; // FNV offset basis (32-bit)
            $prime = 16777619;  // FNV prime (32-bit)

            for ($i = 0; $i < strlen($data); $i++) {
                $hash ^= ord($data[$i]);
                $hash = ($hash * $prime) & 0xFFFFFFFF; // Keep 32-bit
            }

            return abs($hash);
        } catch (\Exception $e) {
            $this->logger->error('FNV-1a hash calculation failed', [
                'data_length' => strlen($data),
                'error' => $e->getMessage()
            ]);
            // Fallback to simple hash
            return abs(crc32($data));
        }
    }
}