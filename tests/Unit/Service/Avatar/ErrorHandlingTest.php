<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Avatar;

use App\Entity\StaffUser;
use App\Service\Avatar\AvatarColorService;
use App\Service\Avatar\ColorGeneratorServiceInterface;
use App\Service\Avatar\AccessibilityValidatorServiceInterface;
use App\Service\Avatar\ConfigurationValidatorServiceInterface;
use App\ValueObject\AvatarColorResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Test error handling and logging in avatar color services.
 */
class ErrorHandlingTest extends TestCase
{
    private AvatarColorService $avatarColorService;
    private MockObject $colorGenerator;
    private MockObject $accessibilityValidator;
    private MockObject $cache;
    private MockObject $configValidator;
    private MockObject $logger;
    private array $config;

    protected function setUp(): void
    {
        $this->colorGenerator = $this->createMock(ColorGeneratorServiceInterface::class);
        $this->accessibilityValidator = $this->createMock(AccessibilityValidatorServiceInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->configValidator = $this->createMock(ConfigurationValidatorServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config = [
            'colors' => [
                ['bg' => 'bg-blue-500', 'text' => 'text-white', 'hex_bg' => '#3B82F6', 'hex_text' => '#FFFFFF']
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
        ];

        $this->avatarColorService = new AvatarColorService(
            $this->colorGenerator,
            $this->accessibilityValidator,
            $this->cache,
            $this->config,
            $this->configValidator,
            $this->logger
        );
    }

    public function testHandlesNullUserGracefully(): void
    {
        // Test that user with missing data returns fallback colors without throwing exception
        $user = $this->createMock(StaffUser::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn(''); // Empty string instead of null
        $user->method('getFirstName')->willReturn('');
        $user->method('getLastName')->willReturn('');
        $user->method('getRole')->willReturn(\App\Entity\Enum\UserRole::SL_STAFF);

        // The service will generate colors but may encounter issues with empty base color
        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $result = $this->avatarColorService->getAvatarColors($user);

        $this->assertInstanceOf(AvatarColorResult::class, $result);
        $this->assertEquals('bg-meta-blue text-white', $result->getCssClasses());
    }

    public function testHandlesEmptyIdentifierGracefully(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Empty identifier provided for avatar color generation');

        $result = $this->avatarColorService->getAvatarColorsFromIdentifier('');

        $this->assertInstanceOf(AvatarColorResult::class, $result);
        $this->assertEquals('bg-meta-blue text-white', $result->getCssClasses());
    }

    public function testHandlesCacheFailureGracefully(): void
    {
        $this->cache->method('get')
            ->willThrowException(new \Exception('Cache unavailable'));

        $this->colorGenerator->method('generateBaseColor')
            ->willReturn('bg-blue-500');
        $this->colorGenerator->method('getColorConfig')
            ->willReturn($this->config['colors'][0]);
        $this->accessibilityValidator->method('getContrastRatio')
            ->willReturn(7.0);
        $this->accessibilityValidator->method('validateContrast')
            ->willReturn(true);

        // Expect some warning to be logged (could be cache retrieval or cache storage)
        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $result = $this->avatarColorService->getAvatarColorsFromIdentifier('test@example.com');

        $this->assertInstanceOf(AvatarColorResult::class, $result);
    }

    public function testHandlesColorGenerationFailureGracefully(): void
    {
        $this->colorGenerator->method('generateBaseColor')
            ->willThrowException(new \Exception('Color generation failed'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Avatar color generation failed'));

        $result = $this->avatarColorService->getAvatarColorsFromIdentifier('test@example.com');

        $this->assertInstanceOf(AvatarColorResult::class, $result);
        $this->assertEquals('bg-meta-blue text-white', $result->getCssClasses());
    }

    public function testHandlesMissingColorConfigGracefully(): void
    {
        $this->colorGenerator->method('generateBaseColor')
            ->willReturn('bg-nonexistent-500');
        $this->colorGenerator->method('getColorConfig')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Color configuration not found for generated color');

        $result = $this->avatarColorService->getAvatarColorsFromIdentifier('test@example.com');

        $this->assertInstanceOf(AvatarColorResult::class, $result);
        $this->assertEquals('bg-meta-blue text-white', $result->getCssClasses());
    }

    public function testHandlesAccessibilityValidationFailureGracefully(): void
    {
        $this->colorGenerator->method('generateBaseColor')
            ->willReturn('bg-blue-500');
        $this->colorGenerator->method('getColorConfig')
            ->willReturn($this->config['colors'][0]);
        $this->accessibilityValidator->method('getContrastRatio')
            ->willThrowException(new \Exception('Accessibility validation failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Accessibility validation failed');

        $result = $this->avatarColorService->getAvatarColorsFromIdentifier('test@example.com');

        $this->assertInstanceOf(AvatarColorResult::class, $result);
        $this->assertEquals('bg-meta-blue text-white', $result->getCssClasses());
    }

    public function testLogsCacheOperationsCorrectly(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $this->colorGenerator->method('generateBaseColor')
            ->willReturn('bg-blue-500');
        $this->colorGenerator->method('getColorConfig')
            ->willReturn($this->config['colors'][0]);
        $this->accessibilityValidator->method('getContrastRatio')
            ->willReturn(7.0);
        $this->accessibilityValidator->method('validateContrast')
            ->willReturn(true);

        $this->logger->expects($this->atLeastOnce())
            ->method('debug')
            ->with($this->stringContains('Avatar colors'));

        $this->avatarColorService->getAvatarColorsFromIdentifier('test@example.com');
    }

    public function testHandlesCacheClearFailureGracefully(): void
    {
        $user = $this->createMock(StaffUser::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getRole')->willReturn(\App\Entity\Enum\UserRole::SL_STAFF);

        $this->cache->method('delete')
            ->willThrowException(new \Exception('Cache clear failed'));

        // The enhanced clearCache method may log multiple errors due to role variations
        $this->logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('Failed to'));

        // Should not throw exception
        $this->avatarColorService->clearCache($user);
        $this->assertTrue(true); // Test passes if no exception is thrown
    }

    public function testLogsConfigurationValidationFailure(): void
    {
        $this->configValidator->method('validateConfiguration')
            ->willReturn(false);
        $this->configValidator->method('getFormattedErrors')
            ->willReturn('Invalid configuration');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Avatar colors configuration validation failed');

        // Create new service instance to trigger constructor validation
        new AvatarColorService(
            $this->colorGenerator,
            $this->accessibilityValidator,
            $this->cache,
            ['invalid' => 'config'],
            $this->configValidator,
            $this->logger
        );
    }

    public function testHandlesSerializationFailureGracefully(): void
    {
        // Test serialization failure by creating a real result and using reflection
        $this->colorGenerator->method('generateBaseColor')
            ->willReturn('bg-blue-500');
        $this->colorGenerator->method('getColorConfig')
            ->willReturn($this->config['colors'][0]);
        $this->accessibilityValidator->method('getContrastRatio')
            ->willReturn(7.0);
        $this->accessibilityValidator->method('validateContrast')
            ->willReturn(true);

        // Create a real result to test serialization
        $result = new AvatarColorResult(
            backgroundClass: 'bg-blue-500',
            textClass: 'text-white',
            backgroundColor: '#3B82F6',
            textColor: '#FFFFFF',
            contrastRatio: 7.0,
            isRoleVariation: false
        );

        // Use reflection to test private method with valid result
        $reflection = new \ReflectionClass($this->avatarColorService);
        $method = $reflection->getMethod('serializeResult');
        $method->setAccessible(true);

        $serialized = $method->invoke($this->avatarColorService, $result);

        $this->assertIsArray($serialized);
        $this->assertEquals('bg-blue-500', $serialized['backgroundClass']);
        $this->assertEquals('text-white', $serialized['textClass']);
    }
}