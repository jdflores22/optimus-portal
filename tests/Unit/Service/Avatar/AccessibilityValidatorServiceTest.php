<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Avatar;

use App\Service\Avatar\AccessibilityValidatorService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AccessibilityValidatorService.
 */
class AccessibilityValidatorServiceTest extends TestCase
{
    private AccessibilityValidatorService $service;
    private array $testConfig;

    protected function setUp(): void
    {
        $this->testConfig = [
            'accessibility' => [
                'min_contrast_ratio' => 4.5,
                'enforce_wcag_aa' => true
            ]
        ];

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->service = new AccessibilityValidatorService($this->testConfig, $logger);
    }

    public function testValidateContrastReturnsTrueForGoodContrast(): void
    {
        // White text on dark blue background - should have good contrast
        $result = $this->service->validateContrast('#1E40AF', '#FFFFFF');
        
        $this->assertTrue($result);
    }

    public function testValidateContrastReturnsFalseForPoorContrast(): void
    {
        // Light gray text on white background - should have poor contrast
        $result = $this->service->validateContrast('#FFFFFF', '#CCCCCC');
        
        $this->assertFalse($result);
    }

    public function testValidateContrastReturnsTrueWhenEnforcementDisabled(): void
    {
        $configWithoutEnforcement = $this->testConfig;
        $configWithoutEnforcement['accessibility']['enforce_wcag_aa'] = false;

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $service = new AccessibilityValidatorService($configWithoutEnforcement, $logger);

        // Even poor contrast should return true when enforcement is disabled
        $result = $service->validateContrast('#FFFFFF', '#CCCCCC');
        
        $this->assertTrue($result);
    }

    public function testGetContrastRatioCalculatesCorrectly(): void
    {
        // Black text on white background should have maximum contrast (21:1)
        $ratio = $this->service->getContrastRatio('#000000', '#FFFFFF');
        
        $this->assertEqualsWithDelta(21.0, $ratio, 0.1);
    }

    public function testGetContrastRatioWithSameColorsReturnsOne(): void
    {
        // Same colors should have 1:1 contrast ratio
        $ratio = $this->service->getContrastRatio('#3B82F6', '#3B82F6');
        
        $this->assertEqualsWithDelta(1.0, $ratio, 0.1);
    }

    public function testGetContrastRatioHandlesColorOrderCorrectly(): void
    {
        // Contrast ratio should be the same regardless of color order
        $ratio1 = $this->service->getContrastRatio('#000000', '#FFFFFF');
        $ratio2 = $this->service->getContrastRatio('#FFFFFF', '#000000');
        
        $this->assertEquals($ratio1, $ratio2);
    }

    public function testSuggestTextColorReturnsWhiteForDarkBackground(): void
    {
        // Dark blue background should suggest white text
        $suggestion = $this->service->suggestTextColor('#1E40AF');
        
        $this->assertEquals('text-white', $suggestion);
    }

    public function testSuggestTextColorReturnsBlackForLightBackground(): void
    {
        // Light yellow background should suggest black text
        $suggestion = $this->service->suggestTextColor('#FEF3C7');
        
        $this->assertEquals('text-black', $suggestion);
    }

    public function testSuggestTextColorHandlesMediumColors(): void
    {
        // Medium gray should suggest white text (threshold test)
        $suggestion = $this->service->suggestTextColor('#6B7280');
        
        $this->assertEquals('text-white', $suggestion);
    }

    public function testGetMinimumContrastRatioReturnsConfiguredValue(): void
    {
        $ratio = $this->service->getMinimumContrastRatio();
        
        $this->assertEquals(4.5, $ratio);
    }

    public function testGetMinimumContrastRatioUsesDefaultWhenNotConfigured(): void
    {
        $configWithoutRatio = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $service = new AccessibilityValidatorService($configWithoutRatio, $logger);
        
        $ratio = $service->getMinimumContrastRatio();
        
        $this->assertEquals(4.5, $ratio);
    }

    public function testContrastCalculationWithHexColorsWithoutHash(): void
    {
        // Should handle hex colors without # prefix
        $ratio = $this->service->getContrastRatio('000000', 'FFFFFF');
        
        $this->assertEqualsWithDelta(21.0, $ratio, 0.1);
    }

    public function testContrastCalculationWithMixedHexFormats(): void
    {
        // Should handle mixed formats (with and without #)
        $ratio = $this->service->getContrastRatio('#000000', 'FFFFFF');
        
        $this->assertEqualsWithDelta(21.0, $ratio, 0.1);
    }

    /**
     * Test specific color combinations from the avatar color palette
     */
    public function testAvatarPaletteColorContrasts(): void
    {
        // Test with colors that should definitely pass
        $passingTests = [
            ['#000000', '#FFFFFF'], // Black + White (maximum contrast)
            ['#1F2937', '#FFFFFF'], // Gray 800 + White (should pass)
        ];

        foreach ($passingTests as [$bg, $text]) {
            $result = $this->service->validateContrast($bg, $text);
            $ratio = $this->service->getContrastRatio($bg, $text);
            
            $this->assertTrue($result, 
                "Color combination {$bg} + {$text} should pass contrast validation (ratio: {$ratio})"
            );
            $this->assertGreaterThanOrEqual(4.5, $ratio);
        }

        // Test with colors that should fail
        $failingTests = [
            ['#FFFFFF', '#CCCCCC'], // White + Light Gray (poor contrast)
            ['#F3F4F6', '#E5E7EB'], // Very light colors
        ];

        foreach ($failingTests as [$bg, $text]) {
            $result = $this->service->validateContrast($bg, $text);
            $ratio = $this->service->getContrastRatio($bg, $text);
            
            $this->assertFalse($result, 
                "Color combination {$bg} + {$text} should fail contrast validation (ratio: {$ratio})"
            );
            $this->assertLessThan(4.5, $ratio);
        }
    }
}