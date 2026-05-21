<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Avatar;

use App\Service\Avatar\ConfigurationValidatorService;
use App\Service\Avatar\AvatarColorService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for configuration validation with avatar color services.
 */
class ConfigurationValidationIntegrationTest extends KernelTestCase
{
    private ConfigurationValidatorService $validator;
    private AvatarColorService $avatarColorService;

    protected function setUp(): void
    {
        self::bootKernel();
        
        $this->validator = self::getContainer()->get(ConfigurationValidatorService::class);
        $this->avatarColorService = self::getContainer()->get(AvatarColorService::class);
    }

    public function testValidateCurrentAvatarColorsConfiguration(): void
    {
        // Get the current configuration from the service container
        $avatarColorsConfig = self::getContainer()->getParameter('avatar_colors');
        
        // Validate the current configuration
        $result = $this->validator->validateConfiguration(['avatar_colors' => $avatarColorsConfig]);
        
        if (!$result) {
            $errors = $this->validator->getFormattedErrors();
            $this->fail("Current avatar colors configuration is invalid:\n" . $errors);
        }
        
        $this->assertTrue($result);
        $this->assertEmpty($this->validator->getValidationErrors());
    }

    public function testAvatarColorServiceWorksWithValidatedConfiguration(): void
    {
        // Test that the avatar color service can work with the validated configuration
        $testIdentifier = 'test@example.com';
        
        $result = $this->avatarColorService->getAvatarColorsFromIdentifier($testIdentifier);
        
        $this->assertNotNull($result);
        $this->assertNotEmpty($result->backgroundClass);
        $this->assertNotEmpty($result->textClass);
        $this->assertGreaterThan(0, $result->contrastRatio);
    }

    public function testConfigurationValidatorServiceIsRegistered(): void
    {
        $this->assertInstanceOf(ConfigurationValidatorService::class, $this->validator);
    }

    public function testValidateConfigurationFileWithCurrentFile(): void
    {
        $configPath = self::getContainer()->getParameter('kernel.project_dir') . '/config/packages/avatar_colors.yaml';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('Avatar colors configuration file not found');
        }
        
        $result = $this->validator->validateConfigurationFile($configPath);
        
        if (!$result) {
            $errors = $this->validator->getFormattedErrors();
            $this->fail("Avatar colors configuration file validation failed:\n" . $errors);
        }
        
        $this->assertTrue($result);
    }

    public function testValidateInvalidConfiguration(): void
    {
        $invalidConfig = [
            'avatar_colors' => [
                'colors' => [], // Empty colors array should fail
                'role_variations' => ['enabled' => 'not_boolean'], // Invalid type
                'cache' => ['ttl' => -1], // Invalid TTL
                'accessibility' => ['min_contrast_ratio' => 25.0] // Invalid ratio
            ]
        ];
        
        $result = $this->validator->validateConfiguration($invalidConfig);
        
        $this->assertFalse($result);
        $this->assertNotEmpty($this->validator->getValidationErrors());
        
        $formattedErrors = $this->validator->getFormattedErrors();
        $this->assertStringContainsString('Colors section cannot be empty', $formattedErrors);
        $this->assertStringContainsString('enabled flag must be boolean', $formattedErrors);
        $this->assertStringContainsString('TTL must be a non-negative integer', $formattedErrors);
        $this->assertStringContainsString('contrast ratio must be between', $formattedErrors);
    }
}