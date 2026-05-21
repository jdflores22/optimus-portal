<?php

declare(strict_types=1);

namespace App\Service\Avatar;

use Psr\Log\LoggerInterface;

/**
 * Accessibility validation service ensuring WCAG AA compliance.
 * 
 * This service validates color contrast ratios and provides text color
 * suggestions to ensure all avatar colors meet accessibility standards.
 */
class AccessibilityValidatorService implements AccessibilityValidatorServiceInterface
{
    private float $minContrastRatio;
    private bool $enforceWcagAA;

    public function __construct(array $avatarColorsConfig, private readonly LoggerInterface $logger)
    {
        try {
            $this->minContrastRatio = $avatarColorsConfig['accessibility']['min_contrast_ratio'] ?? 4.5;
            $this->enforceWcagAA = $avatarColorsConfig['accessibility']['enforce_wcag_aa'] ?? true;
            
            $this->logger->debug('AccessibilityValidatorService initialized', [
                'min_contrast_ratio' => $this->minContrastRatio,
                'enforce_wcag_aa' => $this->enforceWcagAA
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize AccessibilityValidatorService', [
                'error' => $e->getMessage(),
                'config' => $avatarColorsConfig
            ]);
            
            // Use safe defaults
            $this->minContrastRatio = 4.5;
            $this->enforceWcagAA = true;
        }
    }

    /**
     * Validate contrast ratio between two colors.
     */
    public function validateContrast(string $backgroundColor, string $textColor): bool
    {
        try {
            if (!$this->enforceWcagAA) {
                $this->logger->debug('WCAG AA enforcement disabled, skipping contrast validation');
                return true;
            }

            $contrastRatio = $this->getContrastRatio($backgroundColor, $textColor);
            $isValid = $contrastRatio >= $this->minContrastRatio;
            
            $this->logger->debug('Contrast validation performed', [
                'background_color' => $backgroundColor,
                'text_color' => $textColor,
                'contrast_ratio' => $contrastRatio,
                'min_required' => $this->minContrastRatio,
                'is_valid' => $isValid
            ]);
            
            return $isValid;
        } catch (\Exception $e) {
            $this->logger->error('Contrast validation failed', [
                'background_color' => $backgroundColor,
                'text_color' => $textColor,
                'error' => $e->getMessage()
            ]);
            // Assume invalid on error to be safe
            return false;
        }
    }

    /**
     * Calculate contrast ratio between two colors.
     */
    public function getContrastRatio(string $color1, string $color2): float
    {
        try {
            $luminance1 = $this->getLuminance($color1);
            $luminance2 = $this->getLuminance($color2);

            // Ensure lighter color is in numerator
            $lighter = max($luminance1, $luminance2);
            $darker = min($luminance1, $luminance2);

            $ratio = ($lighter + 0.05) / ($darker + 0.05);
            
            $this->logger->debug('Contrast ratio calculated', [
                'color1' => $color1,
                'color2' => $color2,
                'luminance1' => $luminance1,
                'luminance2' => $luminance2,
                'contrast_ratio' => $ratio
            ]);
            
            return $ratio;
        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate contrast ratio', [
                'color1' => $color1,
                'color2' => $color2,
                'error' => $e->getMessage()
            ]);
            // Return a safe default that indicates poor contrast
            return 1.0;
        }
    }

    /**
     * Suggest appropriate text color for a background color.
     */
    public function suggestTextColor(string $backgroundColor): string
    {
        try {
            $luminance = $this->getLuminance($backgroundColor);
            
            // Use white text for dark backgrounds, black for light backgrounds
            // Threshold of 0.5 works well for most cases
            $suggestedColor = $luminance > 0.5 ? 'text-black' : 'text-white';
            
            $this->logger->debug('Text color suggested', [
                'background_color' => $backgroundColor,
                'luminance' => $luminance,
                'suggested_text_color' => $suggestedColor
            ]);
            
            return $suggestedColor;
        } catch (\Exception $e) {
            $this->logger->error('Failed to suggest text color', [
                'background_color' => $backgroundColor,
                'error' => $e->getMessage()
            ]);
            // Default to white text as it works with most dark colors
            return 'text-white';
        }
    }

    /**
     * Get the minimum contrast ratio requirement.
     */
    public function getMinimumContrastRatio(): float
    {
        return $this->minContrastRatio;
    }

    /**
     * Calculate relative luminance of a color.
     * 
     * @param string $hexColor Hex color code (with or without #)
     * @return float Relative luminance (0.0 to 1.0)
     */
    private function getLuminance(string $hexColor): float
    {
        try {
            // Remove # if present
            $hexColor = ltrim($hexColor, '#');
            
            // Validate hex color format
            if (!preg_match('/^[0-9a-fA-F]{6}$/', $hexColor)) {
                $this->logger->warning('Invalid hex color format', [
                    'hex_color' => $hexColor
                ]);
                // Return a safe default luminance
                return 0.0;
            }
            
            // Convert to RGB
            $r = hexdec(substr($hexColor, 0, 2)) / 255;
            $g = hexdec(substr($hexColor, 2, 2)) / 255;
            $b = hexdec(substr($hexColor, 4, 2)) / 255;

            // Apply gamma correction
            $r = $this->gammaCorrect($r);
            $g = $this->gammaCorrect($g);
            $b = $this->gammaCorrect($b);

            // Calculate relative luminance using WCAG formula
            $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
            
            $this->logger->debug('Luminance calculated', [
                'hex_color' => $hexColor,
                'rgb' => [$r, $g, $b],
                'luminance' => $luminance
            ]);
            
            return $luminance;
        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate luminance', [
                'hex_color' => $hexColor,
                'error' => $e->getMessage()
            ]);
            // Return a safe default
            return 0.0;
        }
    }

    /**
     * Apply gamma correction to RGB component.
     */
    private function gammaCorrect(float $component): float
    {
        try {
            if ($component <= 0.03928) {
                return $component / 12.92;
            }
            
            return pow(($component + 0.055) / 1.055, 2.4);
        } catch (\Exception $e) {
            $this->logger->warning('Gamma correction failed', [
                'component' => $component,
                'error' => $e->getMessage()
            ]);
            // Return the original component as fallback
            return $component;
        }
    }
}