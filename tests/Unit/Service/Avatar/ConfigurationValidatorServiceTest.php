<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Avatar;

use App\Service\Avatar\ConfigurationValidatorService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigurationValidatorService.
 */
class ConfigurationValidatorServiceTest extends TestCase
{
    private ConfigurationValidatorService $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigurationValidatorService();
    }

    public function testValidateValidConfiguration(): void
    {
        $config = [
            'avatar_colors' => [
                'colors' => [
                    ['bg' => 'bg-blue-500', 'text' => 'text-white', 'hex_bg' => '#3B82F6', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-green-500', 'text' => 'text-black', 'hex_bg' => '#10B981', 'hex_text' => '#000000'],
                    ['bg' => 'bg-purple-500', 'text' => 'text-white', 'hex_bg' => '#8B5CF6', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-pink-500', 'text' => 'text-white', 'hex_bg' => '#EC4899', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-indigo-500', 'text' => 'text-white', 'hex_bg' => '#6366F1', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-red-500', 'text' => 'text-white', 'hex_bg' => '#EF4444', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-yellow-500', 'text' => 'text-black', 'hex_bg' => '#F59E0B', 'hex_text' => '#000000'],
                    ['bg' => 'bg-orange-500', 'text' => 'text-black', 'hex_bg' => '#F97316', 'hex_text' => '#000000'],
                    ['bg' => 'bg-teal-500', 'text' => 'text-white', 'hex_bg' => '#14B8A6', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-cyan-500', 'text' => 'text-white', 'hex_bg' => '#06B6D4', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-emerald-500', 'text' => 'text-white', 'hex_bg' => '#10B981', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-lime-500', 'text' => 'text-black', 'hex_bg' => '#84CC16', 'hex_text' => '#000000'],
                ],
                'role_variations' => [
                    'enabled' => true,
                    'variations' => [
                        'SHIPPING_LINES_ADMIN' => ['intensity' => 600],
                        'SL_STAFF' => ['intensity' => 400],
                    ]
                ],
                'cache' => [
                    'enabled' => true,
                    'ttl' => 3600,
                    'key_prefix' => 'avatar_colors'
                ],
                'accessibility' => [
                    'min_contrast_ratio' => 4.5,
                    'enforce_wcag_aa' => true
                ]
            ]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->getValidationErrors());
    }

    public function testValidateConfigurationWithMissingColors(): void
    {
        $config = [
            'avatar_colors' => [
                'colors' => [],
                'role_variations' => ['enabled' => true],
                'cache' => ['enabled' => true],
                'accessibility' => ['min_contrast_ratio' => 4.5]
            ]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertFalse($result);
        
        $errors = $this->validator->getValidationErrors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Colors section cannot be empty', $this->validator->getFormattedErrors());
    }

    public function testValidateConfigurationWithInvalidColorEntry(): void
    {
        $config = [
            'avatar_colors' => [
                'colors' => [
                    ['bg' => 'invalid-class', 'text' => 'text-white'], // Missing required fields
                    ['bg' => 'bg-blue-500', 'text' => 'text-white', 'hex_bg' => '#3B82F6', 'hex_text' => '#FFFFFF'],
                ],
                'role_variations' => ['enabled' => true],
                'cache' => ['enabled' => true],
                'accessibility' => ['min_contrast_ratio' => 4.5]
            ]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertFalse($result);
        
        $errors = $this->validator->getValidationErrors();
        $this->assertNotEmpty($errors);
        $formattedErrors = $this->validator->getFormattedErrors();
        $this->assertStringContainsString('missing required field', $formattedErrors);
        $this->assertStringContainsString('Invalid background CSS class', $formattedErrors);
    }

    public function testValidateConfigurationWithInvalidRoleVariations(): void
    {
        $config = [
            'avatar_colors' => [
                'colors' => $this->getValidColors(),
                'role_variations' => [
                    'enabled' => 'not_boolean', // Invalid type
                    'variations' => [
                        'INVALID_ROLE' => ['intensity' => 600], // Unsupported role
                        'SHIPPING_LINES_ADMIN' => ['intensity' => 999], // Invalid intensity
                    ]
                ],
                'cache' => ['enabled' => true],
                'accessibility' => ['min_contrast_ratio' => 4.5]
            ]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertFalse($result);
        
        $formattedErrors = $this->validator->getFormattedErrors();
        $this->assertStringContainsString('enabled flag must be boolean', $formattedErrors);
        $this->assertStringContainsString('Unsupported role', $formattedErrors);
        $this->assertStringContainsString('Invalid intensity', $formattedErrors);
    }

    public function testValidateConfigurationWithInvalidCache(): void
    {
        $config = [
            'avatar_colors' => [
                'colors' => $this->getValidColors(),
                'role_variations' => ['enabled' => true],
                'cache' => [
                    'enabled' => 'not_boolean',
                    'ttl' => -1,
                    'key_prefix' => ''
                ],
                'accessibility' => ['min_contrast_ratio' => 4.5]
            ]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertFalse($result);
        
        $formattedErrors = $this->validator->getFormattedErrors();
        $this->assertStringContainsString('Cache enabled flag must be boolean', $formattedErrors);
        $this->assertStringContainsString('Cache TTL must be a non-negative integer', $formattedErrors);
        $this->assertStringContainsString('Cache key prefix must be a non-empty string', $formattedErrors);
    }

    public function testValidateConfigurationWithInvalidAccessibility(): void
    {
        $config = [
            'avatar_colors' => [
                'colors' => $this->getValidColors(),
                'role_variations' => ['enabled' => true],
                'cache' => ['enabled' => true],
                'accessibility' => [
                    'min_contrast_ratio' => 25.0, // Invalid ratio
                    'enforce_wcag_aa' => 'not_boolean'
                ]
            ]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertFalse($result);
        
        $formattedErrors = $this->validator->getFormattedErrors();
        $this->assertStringContainsString('Minimum contrast ratio must be between 1.0 and 21.0', $formattedErrors);
        $this->assertStringContainsString('Enforce WCAG AA flag must be boolean', $formattedErrors);
    }

    public function testValidateConfigurationWithInsufficientColors(): void
    {
        $config = [
            'avatar_colors' => [
                'colors' => [
                    ['bg' => 'bg-blue-500', 'text' => 'text-white', 'hex_bg' => '#3B82F6', 'hex_text' => '#FFFFFF'],
                    ['bg' => 'bg-green-500', 'text' => 'text-black', 'hex_bg' => '#10B981', 'hex_text' => '#000000'],
                ], // Only 2 colors, need at least 12
                'role_variations' => ['enabled' => true],
                'cache' => ['enabled' => true],
                'accessibility' => ['min_contrast_ratio' => 4.5]
            ]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertFalse($result);
        
        $formattedErrors = $this->validator->getFormattedErrors();
        $this->assertStringContainsString('At least 12 colors required', $formattedErrors);
    }

    public function testValidateConfigurationWithParametersWrapper(): void
    {
        $config = [
            'parameters' => [
                'avatar_colors' => [
                    'colors' => $this->getValidColors(),
                    'role_variations' => ['enabled' => true],
                    'cache' => ['enabled' => true],
                    'accessibility' => ['min_contrast_ratio' => 4.5]
                ]
            ]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->getValidationErrors());
    }

    public function testValidateConfigurationWithDirectStructure(): void
    {
        $config = [
            'colors' => $this->getValidColors(),
            'role_variations' => ['enabled' => true],
            'cache' => ['enabled' => true],
            'accessibility' => ['min_contrast_ratio' => 4.5]
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->getValidationErrors());
    }

    public function testValidateConfigurationWithMissingRootStructure(): void
    {
        $config = [
            'some_other_config' => ['value' => 'test']
        ];

        $result = $this->validator->validateConfiguration($config);
        $this->assertFalse($result);
        
        $formattedErrors = $this->validator->getFormattedErrors();
        $this->assertStringContainsString('Configuration must contain avatar_colors section', $formattedErrors);
    }

    public function testGetFormattedErrorsWithNoErrors(): void
    {
        $config = [
            'avatar_colors' => [
                'colors' => $this->getValidColors(),
                'role_variations' => ['enabled' => true],
                'cache' => ['enabled' => true],
                'accessibility' => ['min_contrast_ratio' => 4.5]
            ]
        ];

        $this->validator->validateConfiguration($config);
        $this->assertEmpty($this->validator->getFormattedErrors());
    }

    private function getValidColors(): array
    {
        return [
            ['bg' => 'bg-blue-500', 'text' => 'text-white', 'hex_bg' => '#3B82F6', 'hex_text' => '#FFFFFF'],
            ['bg' => 'bg-green-500', 'text' => 'text-black', 'hex_bg' => '#10B981', 'hex_text' => '#000000'],
            ['bg' => 'bg-purple-500', 'text' => 'text-white', 'hex_bg' => '#8B5CF6', 'hex_text' => '#FFFFFF'],
            ['bg' => 'bg-pink-500', 'text' => 'text-white', 'hex_bg' => '#EC4899', 'hex_text' => '#FFFFFF'],
            ['bg' => 'bg-indigo-500', 'text' => 'text-white', 'hex_bg' => '#6366F1', 'hex_text' => '#FFFFFF'],
            ['bg' => 'bg-red-500', 'text' => 'text-white', 'hex_bg' => '#EF4444', 'hex_text' => '#FFFFFF'],
            ['bg' => 'bg-yellow-500', 'text' => 'text-black', 'hex_bg' => '#F59E0B', 'hex_text' => '#000000'],
            ['bg' => 'bg-orange-500', 'text' => 'text-black', 'hex_bg' => '#F97316', 'hex_text' => '#000000'],
            ['bg' => 'bg-teal-500', 'text' => 'text-white', 'hex_bg' => '#14B8A6', 'hex_text' => '#FFFFFF'],
            ['bg' => 'bg-cyan-500', 'text' => 'text-white', 'hex_bg' => '#06B6D4', 'hex_text' => '#FFFFFF'],
            ['bg' => 'bg-emerald-500', 'text' => 'text-white', 'hex_bg' => '#10B981', 'hex_text' => '#FFFFFF'],
            ['bg' => 'bg-lime-500', 'text' => 'text-black', 'hex_bg' => '#84CC16', 'hex_text' => '#000000'],
        ];
    }
}