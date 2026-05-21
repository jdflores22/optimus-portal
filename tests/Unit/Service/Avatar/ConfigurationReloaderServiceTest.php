<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Avatar;

use App\Service\Avatar\ConfigurationReloaderService;
use App\Service\Avatar\ConfigurationValidatorServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ConfigurationReloaderService.
 */
class ConfigurationReloaderServiceTest extends TestCase
{
    private ConfigurationReloaderService $reloader;
    private MockObject|ConfigurationValidatorServiceInterface $validator;
    private MockObject|CacheInterface $cache;
    private MockObject|LoggerInterface $logger;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(ConfigurationValidatorServiceInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->projectDir = '/tmp/test';

        $this->reloader = new ConfigurationReloaderService(
            $this->validator,
            $this->cache,
            $this->logger,
            $this->projectDir
        );
    }

    public function testGetCurrentConfigurationFromCache(): void
    {
        $cachedConfig = ['colors' => [], 'role_variations' => ['enabled' => true]];

        $this->cache->expects($this->once())
            ->method('get')
            ->with('avatar_colors_config')
            ->willReturn($cachedConfig);

        $result = $this->reloader->getCurrentConfiguration();

        $this->assertEquals($cachedConfig, $result);
    }

    public function testValidateCurrentConfiguration(): void
    {
        $this->validator->expects($this->once())
            ->method('validateConfigurationFile')
            ->willReturn(true);

        $result = $this->reloader->validateCurrentConfiguration();

        $this->assertTrue($result);
    }

    public function testGetValidationErrors(): void
    {
        $errors = [['code' => 'test', 'message' => 'Test error']];

        $this->validator->expects($this->once())
            ->method('getValidationErrors')
            ->willReturn($errors);

        $result = $this->reloader->getValidationErrors();

        $this->assertEquals($errors, $result);
    }

    public function testUpdateConfigurationValidationFailure(): void
    {
        $newConfig = ['invalid' => 'config'];

        // Mock validator failure
        $this->validator->expects($this->once())
            ->method('validateConfiguration')
            ->with($newConfig)
            ->willReturn(false);
        $this->validator->expects($this->once())
            ->method('getFormattedErrors')
            ->willReturn('Validation errors');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validation errors');

        $this->reloader->updateConfiguration($newConfig);
    }

    public function testIsConfigurationModifiedFileNotExists(): void
    {
        // Test with non-existent file
        $result = $this->reloader->isConfigurationModified();

        $this->assertFalse($result);
    }
}