<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Avatar;

use App\Service\Avatar\ColorGeneratorService;
use App\Service\Avatar\AccessibilityValidatorService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for role variation functionality.
 */
class RoleVariationIntegrationTest extends KernelTestCase
{
    private ColorGeneratorService $colorGenerator;
    private AccessibilityValidatorService $accessibilityValidator;
    private array $config;

    protected function setUp(): void
    {
        self::bootKernel();
        
        // Get the actual configuration from the container
        $this->config = self::getContainer()->getParameter('avatar_colors');
        
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->colorGenerator = new ColorGeneratorService($this->config, $logger);
        $this->accessibilityValidator = new AccessibilityValidatorService($this->config, $logger);
    }

    public function testRoleVariationsAreAppliedCorrectly(): void
    {
        $testIdentifier = 'test@example.com';
        $baseColor = $this->colorGenerator->generateBaseColor($testIdentifier);
        
        // Test each role variation
        $roles = ['SHIPPING_LINES_ADMIN', 'SL_STAFF', 'EVALUATOR', 'ACCOUNTING', 'TERMINAL_TEAM'];
        
        foreach ($roles as $role) {
            $variedColor = $this->colorGenerator->applyRoleVariation($baseColor, $role);
            
            // For roles with different intensities, the color should be different
            $expectedIntensity = $this->config['role_variations']['variations'][$role]['intensity'] ?? 500;
            
            if ($expectedIntensity !== 500) {
                // Extract color name and check if variation exists
                if (preg_match('/bg-(\w+)-500/', $baseColor, $matches)) {
                    $colorName = $matches[1];
                    $expectedVariedColor = "bg-{$colorName}-{$expectedIntensity}";
                    
                    // Check if the varied color exists in configuration
                    $variedConfig = $this->colorGenerator->getColorConfig($expectedVariedColor);
                    
                    if ($variedConfig !== null) {
                        $this->assertEquals($expectedVariedColor, $variedColor, 
                            "Role {$role} should apply intensity {$expectedIntensity}");
                    } else {
                        // If varied color doesn't exist, should fall back to base color
                        $this->assertEquals($baseColor, $variedColor,
                            "Role {$role} should fall back to base color when variation doesn't exist");
                    }
                }
            } else {
                // For roles with intensity 500, should return base color
                $this->assertEquals($baseColor, $variedColor,
                    "Role {$role} with intensity 500 should return base color");
            }
        }
    }

    public function testRoleVariationsMaintainAccessibility(): void
    {
        $testIdentifiers = ['admin@test.com', 'user@example.com', 'staff@company.com'];
        $roles = ['SHIPPING_LINES_ADMIN', 'SL_STAFF', 'EVALUATOR', 'ACCOUNTING', 'TERMINAL_TEAM'];
        
        $accessibilityIssues = [];
        
        foreach ($testIdentifiers as $identifier) {
            $baseColor = $this->colorGenerator->generateBaseColor($identifier);
            
            foreach ($roles as $role) {
                $variedColor = $this->colorGenerator->applyRoleVariation($baseColor, $role);
                $colorConfig = $this->colorGenerator->getColorConfig($variedColor);
                
                if ($colorConfig !== null) {
                    $isAccessible = $this->accessibilityValidator->validateContrast(
                        $colorConfig['hex_bg'],
                        $colorConfig['hex_text']
                    );
                    
                    if (!$isAccessible) {
                        $contrastRatio = $this->accessibilityValidator->getContrastRatio(
                            $colorConfig['hex_bg'],
                            $colorConfig['hex_text']
                        );
                        
                        $accessibilityIssues[] = [
                            'identifier' => $identifier,
                            'role' => $role,
                            'color' => $variedColor,
                            'contrast_ratio' => $contrastRatio
                        ];
                    }
                }
            }
        }
        
        $this->assertEmpty($accessibilityIssues, 
            'All role variations should maintain accessibility compliance. Issues found: ' . 
            json_encode($accessibilityIssues, JSON_PRETTY_PRINT));
    }

    public function testRoleVariationsWithDisabledConfiguration(): void
    {
        // Create a config with role variations disabled
        $disabledConfig = $this->config;
        $disabledConfig['role_variations']['enabled'] = false;
        
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $colorGenerator = new ColorGeneratorService($disabledConfig, $logger);
        
        $testIdentifier = 'test@example.com';
        $baseColor = $colorGenerator->generateBaseColor($testIdentifier);
        
        // All role variations should return the base color when disabled
        $roles = ['SHIPPING_LINES_ADMIN', 'SL_STAFF', 'EVALUATOR'];
        
        foreach ($roles as $role) {
            $variedColor = $colorGenerator->applyRoleVariation($baseColor, $role);
            $this->assertEquals($baseColor, $variedColor,
                "Role variation should be disabled and return base color for role {$role}");
        }
    }

    public function testAllConfiguredColorsHaveValidHexValues(): void
    {
        $colors = $this->colorGenerator->getAvailableColors();
        
        foreach ($colors as $color) {
            // Test that hex values are valid
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $color['hex_bg'],
                "Background hex color {$color['hex_bg']} should be valid");
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $color['hex_text'],
                "Text hex color {$color['hex_text']} should be valid");
            
            // Test that CSS classes follow expected pattern
            $this->assertMatchesRegularExpression('/^bg-\w+-\d+$/', $color['bg'],
                "Background CSS class {$color['bg']} should follow pattern");
            $this->assertMatchesRegularExpression('/^text-(white|black)$/', $color['text'],
                "Text CSS class {$color['text']} should be white or black");
        }
    }
}