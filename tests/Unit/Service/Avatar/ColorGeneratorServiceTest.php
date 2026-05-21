<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Avatar;

use App\Service\Avatar\ColorGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ColorGeneratorService.
 */
class ColorGeneratorServiceTest extends TestCase
{
    private ColorGeneratorService $service;
    private array $testConfig;

    protected function setUp(): void
    {
        $this->testConfig = [
            'colors' => [
                ['bg' => 'bg-blue-400', 'text' => 'text-white', 'hex_bg' => '#60A5FA', 'hex_text' => '#FFFFFF'],
                ['bg' => 'bg-blue-500', 'text' => 'text-white', 'hex_bg' => '#3B82F6', 'hex_text' => '#FFFFFF'],
                ['bg' => 'bg-blue-600', 'text' => 'text-white', 'hex_bg' => '#2563EB', 'hex_text' => '#FFFFFF'],
                ['bg' => 'bg-green-400', 'text' => 'text-white', 'hex_bg' => '#34D399', 'hex_text' => '#FFFFFF'],
                ['bg' => 'bg-green-500', 'text' => 'text-white', 'hex_bg' => '#10B981', 'hex_text' => '#FFFFFF'],
                ['bg' => 'bg-green-600', 'text' => 'text-white', 'hex_bg' => '#059669', 'hex_text' => '#FFFFFF'],
                ['bg' => 'bg-red-400', 'text' => 'text-white', 'hex_bg' => '#F87171', 'hex_text' => '#FFFFFF'],
                ['bg' => 'bg-red-500', 'text' => 'text-white', 'hex_bg' => '#EF4444', 'hex_text' => '#FFFFFF'],
                ['bg' => 'bg-red-600', 'text' => 'text-white', 'hex_bg' => '#DC2626', 'hex_text' => '#FFFFFF'],
            ],
            'role_variations' => [
                'enabled' => true,
                'variations' => [
                    'SHIPPING_LINES_ADMIN' => ['intensity' => 600],
                    'SL_STAFF' => ['intensity' => 400],
                ]
            ]
        ];

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->service = new ColorGeneratorService($this->testConfig, $logger);
    }

    public function testGenerateBaseColorReturnsConsistentResults(): void
    {
        $identifier = 'test@example.com';
        
        $color1 = $this->service->generateBaseColor($identifier);
        $color2 = $this->service->generateBaseColor($identifier);
        
        $this->assertEquals($color1, $color2, 'Same identifier should produce same color');
    }

    public function testGenerateBaseColorReturnsValidTailwindClass(): void
    {
        $identifier = 'test@example.com';
        $color = $this->service->generateBaseColor($identifier);
        
        $validBaseColors = ['bg-blue-500', 'bg-green-500', 'bg-red-500'];
        $this->assertContains($color, $validBaseColors, 'Generated color should be from base color palette (500 intensity)');
    }

    public function testApplyRoleVariationModifiesColor(): void
    {
        $baseColor = 'bg-blue-500';
        $role = 'SHIPPING_LINES_ADMIN';
        
        $variedColor = $this->service->applyRoleVariation($baseColor, $role);
        
        $this->assertEquals('bg-blue-600', $variedColor, 'Role variation should modify intensity');
    }

    public function testApplyRoleVariationWithUnknownRoleReturnsOriginal(): void
    {
        $baseColor = 'bg-blue-500';
        $role = 'UNKNOWN_ROLE';
        
        $variedColor = $this->service->applyRoleVariation($baseColor, $role);
        
        $this->assertEquals($baseColor, $variedColor, 'Unknown role should return original color');
    }

    public function testGetAvailableColorsReturnsConfiguredColors(): void
    {
        $colors = $this->service->getAvailableColors();
        
        $this->assertCount(9, $colors, 'Should return all configured colors including variations');
        $this->assertEquals($this->testConfig['colors'], $colors);
    }

    public function testGetColorConfigReturnsCorrectConfiguration(): void
    {
        $config = $this->service->getColorConfig('bg-blue-500');
        
        $this->assertNotNull($config);
        $this->assertEquals('#3B82F6', $config['hex_bg']);
        $this->assertEquals('text-white', $config['text']);
    }

    public function testGetColorConfigWithInvalidClassReturnsNull(): void
    {
        $config = $this->service->getColorConfig('bg-invalid-500');
        
        $this->assertNull($config);
    }

    public function testGetBaseColorsReturnsOnlyIntensity500Colors(): void
    {
        $baseColors = $this->service->getBaseColors();
        
        $this->assertCount(3, $baseColors, 'Should return only base colors (intensity 500)');
        
        foreach ($baseColors as $color) {
            $this->assertStringEndsWith('-500', $color['bg'], 'Base colors should have intensity 500');
        }
    }
}