<?php

declare(strict_types=1);

namespace App\ValueObject;

/**
 * Value object containing generated avatar color information.
 * 
 * This immutable object holds all color-related data for a user avatar,
 * including CSS classes, hex values, contrast information, and metadata.
 */
final readonly class AvatarColorResult
{
    public function __construct(
        public string $backgroundClass,
        public string $textClass,
        public string $backgroundColor,
        public string $textColor,
        public float $contrastRatio,
        public bool $isRoleVariation = false
    ) {
    }

    /**
     * Create an AvatarColorResult from configuration array.
     */
    public static function fromConfig(array $colorConfig, float $contrastRatio, bool $isRoleVariation = false): self
    {
        return new self(
            backgroundClass: $colorConfig['bg'],
            textClass: $colorConfig['text'],
            backgroundColor: $colorConfig['hex_bg'],
            textColor: $colorConfig['hex_text'],
            contrastRatio: $contrastRatio,
            isRoleVariation: $isRoleVariation
        );
    }

    /**
     * Get combined CSS classes for use in templates.
     */
    public function getCssClasses(): string
    {
        return $this->backgroundClass . ' ' . $this->textClass;
    }

    /**
     * Check if the color combination meets accessibility requirements.
     */
    public function isAccessible(float $minContrastRatio = 4.5): bool
    {
        return $this->contrastRatio >= $minContrastRatio;
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'backgroundClass' => $this->backgroundClass,
            'textClass' => $this->textClass,
            'backgroundColor' => $this->backgroundColor,
            'textColor' => $this->textColor,
            'contrastRatio' => $this->contrastRatio,
            'isRoleVariation' => $this->isRoleVariation,
        ];
    }
}