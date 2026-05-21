<?php

namespace App\Tests\Unit\Service;

use App\Entity\ShippingLine;
use App\Entity\ShippingLineConfiguration;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Service\ShippingLineConfigurationService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationList;

class ShippingLineConfigurationServiceTest extends TestCase
{
    private ShippingLineConfigurationService $service;
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|ValidatorInterface $validator;
    private MockObject|ActivityLogService $activityLogService;
    private MockObject|EntityRepository $repository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->activityLogService = $this->createMock(ActivityLogService::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager
            ->method('getRepository')
            ->with(ShippingLineConfiguration::class)
            ->willReturn($this->repository);

        $this->service = new ShippingLineConfigurationService(
            $this->entityManager,
            $this->validator,
            $this->activityLogService
        );
    }

    public function testSetConfigurationCreatesNewConfiguration(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createSystemAdmin();
        $configKey = 'portal_theme';
        $configValue = ['primaryColor' => '#007bff', 'secondaryColor' => '#6c757d'];

        $this->repository
            ->method('findOneBy')
            ->willReturn(null); // No existing configuration

        $this->validator
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $this->entityManager
            ->expects($this->exactly(2)) // Once for config, once for history
            ->method('persist');

        $this->entityManager
            ->expects($this->exactly(2)) // Once for config, once for history
            ->method('flush');

        $this->activityLogService
            ->expects($this->once())
            ->method('logConfigChange');

        // Act
        $result = $this->service->setConfiguration($shippingLine, $configKey, $configValue, $user);

        // Assert
        $this->assertInstanceOf(ShippingLineConfiguration::class, $result);
        $this->assertEquals($configKey, $result->getConfigKey());
        $this->assertEquals($configValue, $result->getConfigValue());
        $this->assertEquals($shippingLine, $result->getShippingLine());
        $this->assertEquals($user, $result->getCreatedBy());
    }

    public function testSetConfigurationUpdatesExistingConfiguration(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createShippingLineAdmin($shippingLine);
        $configKey = 'portal_theme';
        $oldValue = ['primaryColor' => '#000000'];
        $newValue = ['primaryColor' => '#007bff', 'secondaryColor' => '#6c757d'];

        $existingConfig = new ShippingLineConfiguration();
        $existingConfig->setShippingLine($shippingLine);
        $existingConfig->setConfigKey($configKey);
        $existingConfig->setConfigValue($oldValue);
        $existingConfig->setCreatedBy($user);

        $this->repository
            ->method('findOneBy')
            ->willReturn($existingConfig);

        $this->validator
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $this->entityManager
            ->expects($this->exactly(2)) // Once for config, once for history
            ->method('persist');

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush');

        // Act
        $result = $this->service->setConfiguration($shippingLine, $configKey, $newValue, $user);

        // Assert
        $this->assertEquals($newValue, $result->getConfigValue());
        $this->assertEquals($user, $result->getUpdatedBy());
    }

    public function testSetConfigurationValidatesUserPermissions(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $unauthorizedUser = $this->createUser(UserRole::SL_STAFF);
        $configKey = 'portal_theme';
        $configValue = ['primaryColor' => '#007bff'];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient permissions to modify shipping line configuration');

        $this->service->setConfiguration($shippingLine, $configKey, $configValue, $unauthorizedUser);
    }

    public function testGetConfigurationReturnsCorrectConfiguration(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $configKey = 'portal_theme';
        
        $expectedConfig = new ShippingLineConfiguration();
        $expectedConfig->setShippingLine($shippingLine);
        $expectedConfig->setConfigKey($configKey);

        $this->repository
            ->method('findOneBy')
            ->with([
                'shippingLine' => $shippingLine,
                'configKey' => $configKey,
                'isActive' => true
            ])
            ->willReturn($expectedConfig);

        // Act
        $result = $this->service->getConfiguration($shippingLine, $configKey);

        // Assert
        $this->assertEquals($expectedConfig, $result);
    }

    public function testGetConfigurationValueWithFallbackToDefault(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $configKey = 'portal_theme';
        $defaultValue = ['primaryColor' => '#007bff'];

        $this->repository
            ->method('findOneBy')
            ->willReturn(null); // No configuration found

        // Act
        $result = $this->service->getConfigurationValue($shippingLine, $configKey, $defaultValue);

        // Assert - Should return the default configuration for portal_theme
        $this->assertIsArray($result);
        $this->assertArrayHasKey('primaryColor', $result);
    }

    public function testUpdateBrandingConfigurationValidatesStructure(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createShippingLineAdmin($shippingLine);
        $invalidBrandingConfig = [
            'invalidKey' => 'value',
            'primaryColor' => 'invalid-color-format'
        ];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid branding configuration key: invalidKey');

        $this->service->updateBrandingConfiguration($shippingLine, $invalidBrandingConfig, $user);
    }

    public function testUpdateBrandingConfigurationValidatesColors(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createShippingLineAdmin($shippingLine);
        $invalidBrandingConfig = [
            'primaryColor' => 'not-a-hex-color'
        ];

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid primary color format');

        $this->service->updateBrandingConfiguration($shippingLine, $invalidBrandingConfig, $user);
    }

    public function testDeleteConfigurationRemovesConfiguration(): void
    {
        // Arrange
        $shippingLine = $this->createShippingLine();
        $user = $this->createSystemAdmin();
        $configKey = 'portal_theme';

        $existingConfig = new ShippingLineConfiguration();
        $existingConfig->setShippingLine($shippingLine);
        $existingConfig->setConfigKey($configKey);
        $existingConfig->setConfigValue(['primaryColor' => '#007bff']);
        $existingConfig->setCreatedBy($user);

        $this->repository
            ->method('findOneBy')
            ->willReturn($existingConfig);

        $this->entityManager
            ->expects($this->once())
            ->method('remove')
            ->with($existingConfig);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush');

        // Act
        $this->service->deleteConfiguration($shippingLine, $configKey, $user);

        // Assert - No exception should be thrown
        $this->assertTrue(true);
    }

    private function createShippingLine(): ShippingLine
    {
        $shippingLine = new ShippingLine();
        $shippingLine->setBrandName('Test Shipping Line');
        return $shippingLine;
    }

    private function createSystemAdmin(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('admin@test.com');
        $user->method('getRole')->willReturn(UserRole::SYSTEM_ADMIN);
        $user->method('getManagedShippingLine')->willReturn(null);
        $user->method('getShippingLineAdmin')->willReturn(null);
        return $user;
    }

    private function createShippingLineAdmin(ShippingLine $shippingLine): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('admin@shipping.com');
        $user->method('getRole')->willReturn(UserRole::SHIPPING_LINES_ADMIN);
        $user->method('getManagedShippingLine')->willReturn($shippingLine);
        $user->method('getShippingLineAdmin')->willReturn(null);
        return $user;
    }

    private function createUser(UserRole $role): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('user@test.com');
        $user->method('getRole')->willReturn($role);
        $user->method('getManagedShippingLine')->willReturn(null);
        $user->method('getShippingLineAdmin')->willReturn(null);
        return $user;
    }
}