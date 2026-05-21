<?php

declare(strict_types=1);

namespace App\Service\Avatar;

/**
 * Interface for accessibility validation service.
 * 
 * This service ensures all generated color combinations meet
 * WCAG AA accessibility standards for contrast ratios.
 */
interface AccessibilityValidatorServiceInterface
{
    /**
     * Validate contrast ratio between two colors.
     * 
     * @param string $backgroundColor Hex color code for background
     * @param string $textColor Hex color code for text
     * @return bool True if contrast meets minimum requirements
     */
    public function validateContrast(string $backgroundColor, string $textColor): bool;

    /**
     * Calculate contrast ratio between two colors.
     * 
     * @param string $color1 First hex color code
     * @param string $color2 Second hex color code
     * @return float The contrast ratio (1.0 to 21.0)
     */
    public function getContrastRatio(string $color1, string $color2): float;

    /**
     * Suggest appropriate text color for a background color.
     * 
     * @param string $backgroundColor Hex color code for background
     * @return string Suggested text color ('text-white' or 'text-black')
     */
    public function suggestTextColor(string $backgroundColor): string;

    /**
     * Get the minimum contrast ratio requirement.
     * 
     * @return float The minimum contrast ratio (default 4.5 for WCAG AA)
     */
    public function getMinimumContrastRatio(): float;
}